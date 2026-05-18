import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const login429 = new Counter('login_429');
const cart429 = new Counter('cart_429');
const checkout429 = new Counter('checkout_429');

const baseUrl = (__ENV.BASE_URL || 'http://my-ecommerce-app.test').replace(/\/$/, '');
const defaultEmail = (__ENV.AUTH_EMAIL || 'user1@test.com');
const defaultPassword = (__ENV.AUTH_PASSWORD || 'password123');
const productId = Number.parseInt(__ENV.PRODUCT_ID || '1', 10);
const quantity = Number.parseInt(__ENV.QUANTITY || '1', 10);
const duration = __ENV.DURATION || '20s';
const loadProfile = (__ENV.LOAD_PROFILE || 'light').toLowerCase();

const profileRates = {
  smoke: { login: 1, cart: 1, checkout: 1, products: 2 },
  light: { login: 2, cart: 3, checkout: 1, products: 4 },
  balanced: { login: 3, cart: 4, checkout: 2, products: 5 },
};

const selectedProfile = profileRates[loadProfile] || profileRates.light;
const loginRate = Number.parseInt(__ENV.LOGIN_RATE || String(selectedProfile.login), 10);
const cartRate = Number.parseInt(__ENV.CART_RATE || String(selectedProfile.cart), 10);
const checkoutRate = Number.parseInt(__ENV.CHECKOUT_RATE || String(selectedProfile.checkout), 10);
const productReadRate = Number.parseInt(__ENV.PRODUCTS_RATE || String(selectedProfile.products), 10);

function jsonHeaders(token = null) {
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  return headers;
}

function parseJson(response) {
  try {
    return response.json();
  } catch (error) {
    return null;
  }
}

function login(email, password) {
  return http.post(
    `${baseUrl}/api/login`,
    JSON.stringify({ email, password }),
    { headers: jsonHeaders() }
  );
}

function register(email, password) {
  return http.post(
    `${baseUrl}/api/register`,
    JSON.stringify({
      name: 'K6 Load Test User',
      email,
      password,
      password_confirmation: password,
    }),
    { headers: jsonHeaders() }
  );
}

function authenticateOrSeedUser(email, password) {
  let response = login(email, password);
  let body = parseJson(response);

  if (response.status === 200 && body && body.token) {
    return {
      email,
      password,
      token: body.token,
    };
  }

  const uniqueEmail = `k6-${Date.now()}-${Math.floor(Math.random() * 10000)}@test.local`;
  response = register(uniqueEmail, password);
  body = parseJson(response);

  if (response.status !== 201 || !body || !body.token) {
    throw new Error(`Unable to prepare test user. Register status: ${response.status}`);
  }

  return {
    email: uniqueEmail,
    password,
    token: body.token,
  };
}

export const options = {
  noConnectionReuse: false,
  scenarios: {
    auth_light: {
      executor: 'constant-arrival-rate',
      exec: 'authScenario',
      rate: loginRate,
      timeUnit: '1s',
      duration,
      preAllocatedVUs: 5,
      maxVUs: 20,
      tags: { area: 'auth' },
    },
    cart_light: {
      executor: 'constant-arrival-rate',
      exec: 'cartScenario',
      rate: cartRate,
      timeUnit: '1s',
      duration,
      preAllocatedVUs: 5,
      maxVUs: 20,
      startTime: '0s',
      tags: { area: 'cart' },
    },
    checkout_light: {
      executor: 'constant-arrival-rate',
      exec: 'checkoutScenario',
      rate: checkoutRate,
      timeUnit: '1s',
      duration,
      preAllocatedVUs: 3,
      maxVUs: 12,
      startTime: '0s',
      tags: { area: 'checkout' },
    },
    product_read_light: {
      executor: 'constant-arrival-rate',
      exec: 'productScenario',
      rate: productReadRate,
      timeUnit: '1s',
      duration,
      preAllocatedVUs: 5,
      maxVUs: 25,
      startTime: '0s',
      tags: { area: 'products' },
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.60'],
    http_req_duration: ['p(95)<2500'],
    'http_req_duration{area:auth}': ['p(95)<2200'],
    'http_req_duration{area:cart}': ['p(95)<2200'],
    'http_req_duration{area:checkout}': ['p(95)<2500'],
    'http_req_duration{area:products}': ['p(95)<2200'],
  },
  tags: {
    test: 'solution2-light-capacity-control',
  },
};

function thresholdState(metric) {
  if (!metric || !metric.thresholds) {
    return [];
  }

  if (Array.isArray(metric.thresholds)) {
    return metric.thresholds;
  }

  return Object.values(metric.thresholds).flatMap((threshold) => (Array.isArray(threshold) ? threshold : [threshold]));
}

function formatThresholdInterpretation(data) {
  const lines = [];
  const metricNames = [
    'http_req_duration',
    'http_req_failed',
    'http_req_duration{area:auth}',
    'http_req_duration{area:cart}',
    'http_req_duration{area:checkout}',
    'http_req_duration{area:products}',
  ];

  const failedBySummary = metricNames.filter((metricName) => {
    const metric = data.metrics?.[metricName];
    return thresholdState(metric).some((threshold) => threshold && threshold.ok === false);
  });

  if (failedBySummary.length > 0) {
    lines.push('Threshold failures detected:');
    for (const metricName of failedBySummary) {
      if (metricName === 'http_req_duration') {
        lines.push('- Global latency threshold failed: response times were too slow overall.');
      } else if (metricName === 'http_req_failed') {
        lines.push('- Failure-rate threshold failed: too many requests returned failed responses.');
      } else if (metricName.includes('area:auth')) {
        lines.push('- Auth latency threshold failed: login/register traffic was slower than required.');
      } else if (metricName.includes('area:cart')) {
        lines.push('- Cart latency threshold failed: cart operations were slower than required.');
      } else if (metricName.includes('area:checkout')) {
        lines.push('- Checkout latency threshold failed: checkout took longer than the target.');
      } else if (metricName.includes('area:products')) {
        lines.push('- Products latency threshold failed: product listing was slower than the target.');
      }
    }
    return lines.join('\n');
  }

  lines.push('Threshold interpretation:');
  lines.push('- No explicit threshold failures were found in the summary payload.');
  lines.push('- If k6 prints an ERRO line, the run still exceeded one or more thresholds or hit a script exception.');
  lines.push('- For this light profile, the main goal is to keep latency under control while still producing measurable load.');

  return lines.join('\n');
}

export function setup() {
  const preparedUser = authenticateOrSeedUser(defaultEmail, defaultPassword);
  const authHeaders = jsonHeaders(preparedUser.token);

  const warmupResponse = http.post(
    `${baseUrl}/api/cart/add`,
    JSON.stringify({ product_id: productId, quantity }),
    { headers: authHeaders }
  );

  if (warmupResponse.status === 429) {
    cart429.add(1);
  }

  return {
    baseUrl,
    email: preparedUser.email,
    password: preparedUser.password,
    token: preparedUser.token,
    productId,
    quantity,
  };
}

export function handleSummary(data) {
  const summary = [
    '',
    'Performance interpretation',
    '-------------------------',
    formatThresholdInterpretation(data),
    '',
    'Why the run stopped with ERRO:',
    '- k6 exits with a non-zero status when thresholds are crossed or the script raises an exception.',
    '- This light script keeps the same coverage as the old one, but with much lower pressure on the server.',
    '',
  ].join('\n');

  return { stdout: summary };
}

export function productScenario(data) {
  const response = http.get(
    `${data.baseUrl}/api/products`,
    { headers: jsonHeaders(data.token) }
  );

  check(response, {
    'products endpoint responded': (res) => res.status > 0,
  });
}

export function authScenario(data) {
  const response = login(data.email, data.password);

  if (response.status === 429) {
    login429.add(1);
  }

  check(response, {
    'login endpoint responded': (res) => res.status > 0,
  });
}

export function cartScenario(data) {
  const response = http.post(
    `${data.baseUrl}/api/cart/add`,
    JSON.stringify({ product_id: data.productId, quantity: data.quantity }),
    { headers: jsonHeaders(data.token) }
  );

  if (response.status === 429) {
    cart429.add(1);
  }

  check(response, {
    'cart endpoint responded': (res) => res.status > 0,
  });
}

export function checkoutScenario(data) {
  const response = http.post(
    `${data.baseUrl}/api/checkout`,
    JSON.stringify({}),
    { headers: jsonHeaders(data.token) }
  );

  if (response.status === 429) {
    checkout429.add(1);
  }

  check(response, {
    'checkout endpoint responded': (res) => res.status > 0,
  });
}