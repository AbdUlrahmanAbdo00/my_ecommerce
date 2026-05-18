<?php

class ProjectResourceMonitor
{
    private bool $isWindows;
    private array $cpuBaseline = [];

    public function __construct()
    {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    public function getSnapshot(): array
    {
        $php = $this->getPhpResources();
        $mysql = $this->getMysqlResources();
        $redis = $this->getRedisResources();

        $totalRam = $php['ram_mb']
            + (is_numeric($mysql['ram_mb']) ? $mysql['ram_mb'] : 0)
            + (is_numeric($redis['ram_mb']) ? $redis['ram_mb'] : 0);

        $totalCpu = 0.0;
        if (is_numeric($php['cpu'])) {
            $totalCpu += (float) $php['cpu'];
        }
        if (is_numeric($mysql['cpu'])) {
            $totalCpu += (float) $mysql['cpu'];
        }
        if (is_numeric($redis['cpu'])) {
            $totalCpu += (float) $redis['cpu'];
        }

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'php' => $php,
            'mysql' => $mysql,
            'redis' => $redis,
            'total' => [
                'cpu' => round($totalCpu, 2),
                'ram_mb' => round($totalRam, 2),
            ],
        ];
    }

    public function getPhpResources(): array
    {
        if ($this->isWindows) {
            return $this->getPhpResourcesWindows();
        }

        return $this->getPhpResourcesLinux();
    }

    private function getPhpResourcesWindows(): array
    {
        return $this->getWindowsProcessGroupResources([
            'httpd.exe',
            'php-cgi.exe',
            'php.exe',
        ], 'PHP/Apache');
    }

    private function getPhpResourcesLinux(): array
    {
        $output = shell_exec('ps aux | grep php | grep -v grep');

        if (!$output) {
            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'processes' => 0,
                'status' => 'Not running',
            ];
        }

        $lines = array_filter(explode("\n", trim($output)));
        $totalCpu = 0;
        $totalRam = 0;

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 6) {
                $totalCpu += (float) $parts[2];
                $totalRam += (float) $parts[5];
            }
        }

        return [
            'cpu' => round($totalCpu, 2),
            'ram_mb' => round($totalRam / 1024, 2),
            'processes' => count($lines),
            'status' => 'Running',
        ];
    }

    public function getMysqlResources(): array
    {
        if ($this->isWindows) {
            return $this->getMysqlResourcesWindows();
        }

        return $this->getMysqlResourcesLinux();
    }

    private function getMysqlResourcesWindows(): array
    {
        return $this->getWindowsProcessGroupResources([
            'mysqld.exe',
        ], 'MySQL');
    }

    private function getMysqlResourcesLinux(): array
    {
        $output = shell_exec('ps aux | grep mysqld | grep -v grep');

        if (!$output) {
            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'status' => 'Not running',
            ];
        }

        $parts = preg_split('/\s+/', $output);
        if (count($parts) >= 6) {
            $cpu = (float) $parts[2];
            $ram = (float) $parts[5] / 1024;

            return [
                'cpu' => round($cpu, 2),
                'ram_mb' => round($ram, 2),
                'status' => 'Running',
            ];
        }

        return [
            'cpu' => 0,
            'ram_mb' => 0,
            'status' => 'Not running',
        ];
    }

    public function getRedisResources(): array
    {
        if ($this->isWindows) {
            return $this->getRedisResourcesWindows();
        }

        return $this->getRedisResourcesLinux();
    }

    private function getRedisResourcesWindows(): array
    {
        try {
            $redis = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 1);
            if ($redis) {
                fclose($redis);
                return $this->getWindowsProcessGroupResources([
                    'redis-server.exe',
                ], 'Redis (Service)');
            }
        } catch (Exception $e) {
        }

        return [
            'cpu' => 'N/A',
            'ram_mb' => 0,
            'status' => 'Not running',
        ];
    }

    private function getWindowsProcessGroupResources(array $processNames, string $label): array
    {
        $processes = [];

        foreach ($processNames as $processName) {
            $normalizedName = preg_replace('/\.exe$/i', '', $processName) ?: $processName;
            $command = 'powershell -NoProfile -Command "Get-Process -Name ' . $normalizedName . ' -ErrorAction SilentlyContinue | Select-Object Id,ProcessName,CPU,WorkingSet64 | ConvertTo-Json -Compress" 2>NUL';
            $output = trim((string) shell_exec($command));

            if ($output === '') {
                continue;
            }

            $decoded = json_decode($output, true);
            if ($decoded === null) {
                continue;
            }

            if (isset($decoded[0])) {
                foreach ($decoded as $item) {
                    if (is_array($item)) {
                        $processes[] = $item;
                    }
                }
            } elseif (is_array($decoded)) {
                $processes[] = $decoded;
            }
        }

        if ($processes === []) {
            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'processes' => 0,
                'status' => 'Not running',
            ];
        }
        $ramBytes = 0.0;
        $processCount = 0;
        $currentCpu = [];

        foreach ($processes as $process) {
            if (! is_array($process)) {
                continue;
            }

            $processCount++;
            $pid = (string) ($process['Id'] ?? uniqid($label, true));
            $ramBytes += (float) ($process['WorkingSet64'] ?? 0);
            $currentCpu[$pid] = (float) ($process['CPU'] ?? 0);
        }

        $cpuPercent = $this->calculateCpuPercent($label, $currentCpu);

        return [
            'cpu' => round($cpuPercent, 2),
            'ram_mb' => round($ramBytes / (1024 * 1024), 2),
            'processes' => $processCount,
            'status' => $processCount > 0 ? 'Running' : 'Not running',
        ];
    }

    private function calculateCpuPercent(string $label, array $currentCpu): float
    {
        $key = strtolower($label);
        $now = microtime(true);
        $cores = $this->getLogicalCpuCores();

        if (! isset($this->cpuBaseline[$key])) {
            $this->cpuBaseline[$key] = [
                'timestamp' => $now,
                'cpu' => $currentCpu,
            ];

            return 0.0;
        }

        $previous = $this->cpuBaseline[$key];
        $elapsed = max($now - $previous['timestamp'], 0.001);
        $cpuDelta = 0.0;

        foreach ($currentCpu as $pid => $cpuSeconds) {
            $previousCpu = (float) ($previous['cpu'][$pid] ?? 0.0);
            if ($cpuSeconds > $previousCpu) {
                $cpuDelta += $cpuSeconds - $previousCpu;
            }
        }

        $this->cpuBaseline[$key] = [
            'timestamp' => $now,
            'cpu' => $currentCpu,
        ];

        return ($cpuDelta / $elapsed / max($cores, 1)) * 100;
    }

    private function getLogicalCpuCores(): int
    {
        if (! $this->isWindows) {
            $cores = (int) trim((string) shell_exec('nproc 2>/dev/null'));
            return $cores > 0 ? $cores : 1;
        }

        $cpuCoreOutput = trim((string) shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors" 2>NUL'));
        $cores = (int) $cpuCoreOutput;

        return $cores > 0 ? $cores : 1;
    }

    private function getRedisResourcesLinux(): array
    {
        $output = shell_exec('ps aux | grep redis-server | grep -v grep');

        if (!$output) {
            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'status' => 'Not running',
            ];
        }

        $parts = preg_split('/\s+/', $output);
        if (count($parts) >= 6) {
            $cpu = (float) $parts[2];
            $ram = (float) $parts[5] / 1024;

            return [
                'cpu' => round($cpu, 2),
                'ram_mb' => round($ram, 2),
                'status' => 'Running',
            ];
        }

        return [
            'cpu' => 0,
            'ram_mb' => 0,
            'status' => 'Not running',
        ];
    }

    public function collectSamples(int $durationSeconds, int $intervalSeconds): array
    {
        $durationSeconds = max($durationSeconds, 1);
        $intervalSeconds = max($intervalSeconds, 1);
        $samples = [];
        $startedAt = microtime(true);
        $endAt = $startedAt + $durationSeconds;
        $nextSampleAt = $startedAt;

        while (microtime(true) < $endAt) {
            $samples[] = $this->getSnapshot();
            $nextSampleAt += $intervalSeconds;

            while (microtime(true) < $nextSampleAt && microtime(true) < $endAt) {
                usleep(100000);
            }
        }

        if ($samples === []) {
            $samples[] = $this->getSnapshot();
        }

        return $samples;
    }

    public function summarizeSamples(array $samples, int $durationSeconds, int $intervalSeconds): array
    {
        if ($samples === []) {
            return [
                'duration_seconds' => $durationSeconds,
                'interval_seconds' => $intervalSeconds,
                'samples' => 0,
                'avg_total_cpu' => 0,
                'max_total_cpu' => 0,
                'avg_total_ram_mb' => 0,
                'max_total_ram_mb' => 0,
                'last_snapshot' => null,
            ];
        }

        $cpuValues = [];
        $ramValues = [];

        foreach ($samples as $sample) {
            if (isset($sample['total']['cpu']) && is_numeric($sample['total']['cpu'])) {
                $cpuValues[] = (float) $sample['total']['cpu'];
            }
            if (isset($sample['total']['ram_mb']) && is_numeric($sample['total']['ram_mb'])) {
                $ramValues[] = (float) $sample['total']['ram_mb'];
            }
        }

        return [
            'duration_seconds' => $durationSeconds,
            'interval_seconds' => $intervalSeconds,
            'samples' => count($samples),
            'avg_total_cpu' => $cpuValues !== [] ? round(array_sum($cpuValues) / count($cpuValues), 2) : 0,
            'max_total_cpu' => $cpuValues !== [] ? round(max($cpuValues), 2) : 0,
            'avg_total_ram_mb' => $ramValues !== [] ? round(array_sum($ramValues) / count($ramValues), 2) : 0,
            'max_total_ram_mb' => $ramValues !== [] ? round(max($ramValues), 2) : 0,
            'last_snapshot' => $samples[array_key_last($samples)],
        ];
    }

    public function display(): void
    {
        $snapshot = $this->getSnapshot();
        $php = $snapshot['php'];
        $mysql = $snapshot['mysql'];
        $redis = $snapshot['redis'];

        echo PHP_EOL;
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║       PROJECT RESOURCE MONITOR - Laravel E-Commerce        ║\n";
        echo "╠════════════════════════════════════════════════════════════╣\n";
        echo "║ 🔴 PHP (Laravel Process)                                   ║\n";
        echo "║    Status: " . str_pad($php['status'], 48) . "║\n";
        $cpuText = $php['cpu'] === 'N/A' ? $php['cpu'] : $php['cpu'] . '%';
        echo "║    CPU: " . str_pad($cpuText, 50) . "║\n";
        echo "║    RAM: " . str_pad($php['ram_mb'] . ' MB', 48) . "║\n";
        if (isset($php['processes'])) {
            echo "║    Processes: " . str_pad($php['processes'], 45) . "║\n";
        }
        echo "║                                                            ║\n";
        echo "║ 🟢 MySQL (Database)                                       ║\n";
        echo "║    Status: " . str_pad($mysql['status'], 48) . "║\n";
        $mysqlCpu = is_numeric($mysql['cpu']) ? $mysql['cpu'] . '%' : $mysql['cpu'];
        echo "║    CPU: " . str_pad($mysqlCpu, 50) . "║\n";
        echo "║    RAM: " . str_pad($mysql['ram_mb'] . ' MB', 48) . "║\n";
        echo "║                                                            ║\n";
        echo "║ 🔵 Redis (Cache & Queue)                                  ║\n";
        echo "║    Status: " . str_pad($redis['status'], 48) . "║\n";
        $redisCpu = is_numeric($redis['cpu']) ? $redis['cpu'] . '%' : $redis['cpu'];
        echo "║    CPU: " . str_pad($redisCpu, 50) . "║\n";
        $redisRam = is_string($redis['ram_mb']) ? $redis['ram_mb'] : ($redis['ram_mb'] . ' MB');
        echo "║    RAM: " . str_pad($redisRam, 48) . "║\n";
        echo "║                                                            ║\n";
        echo "╠════════════════════════════════════════════════════════════╣\n";
        echo "║ 📊 TOTAL PROJECT RESOURCES                                ║\n";
        echo "║    CPU Total: " . str_pad($snapshot['total']['cpu'] . '%', 45) . "║\n";
        echo "║    RAM Total: " . str_pad($snapshot['total']['ram_mb'] . ' MB', 45) . "║\n";
        echo "║                                                            ║\n";
        $cpuStatus = $snapshot['total']['cpu'] > 80 ? '🔥 HIGH' : ($snapshot['total']['cpu'] > 50 ? '⚠️  MODERATE' : '✅ NORMAL');
        $ramStatus = $snapshot['total']['ram_mb'] > 500 ? '🔥 HIGH' : ($snapshot['total']['ram_mb'] > 300 ? '⚠️  MODERATE' : '✅ NORMAL');
        echo "║    CPU Status: " . str_pad($cpuStatus, 43) . "║\n";
        echo "║    RAM Status: " . str_pad($ramStatus, 43) . "║\n";
        echo "║                                                            ║\n";
        echo "║ Last Updated: " . str_pad(date('Y-m-d H:i:s'), 42) . "║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo PHP_EOL;
    }

    public function toJson(): string
    {
        return json_encode($this->getSnapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

$monitor = new ProjectResourceMonitor();
$options = getopt('', ['json', 'duration::', 'interval::']);
$duration = isset($options['duration']) ? (int) $options['duration'] : 0;
$interval = isset($options['interval']) ? (int) $options['interval'] : 5;

if (isset($options['json']) && $duration > 0) {
    echo json_encode($monitor->summarizeSamples(
        $monitor->collectSamples($duration, $interval),
        $duration,
        $interval
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} elseif (isset($options['json'])) {
    echo $monitor->toJson();
} elseif ($duration > 0) {
    $samples = $monitor->collectSamples($duration, $interval);
    $summary = $monitor->summarizeSamples($samples, $duration, $interval);

    echo PHP_EOL;
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║        PROJECT RESOURCE MONITOR - K6 TIMED RUN            ║\n";
    echo "╠════════════════════════════════════════════════════════════╣\n";
    echo "║ Duration: " . str_pad($summary['duration_seconds'] . ' seconds', 47) . "║\n";
    echo "║ Interval: " . str_pad($summary['interval_seconds'] . ' seconds', 48) . "║\n";
    echo "║ Samples: " . str_pad($summary['samples'], 50) . "║\n";
    echo "║ Avg CPU: " . str_pad($summary['avg_total_cpu'] . '%', 48) . "║\n";
    echo "║ Max CPU: " . str_pad($summary['max_total_cpu'] . '%', 48) . "║\n";
    echo "║ Avg RAM: " . str_pad($summary['avg_total_ram_mb'] . ' MB', 46) . "║\n";
    echo "║ Max RAM: " . str_pad($summary['max_total_ram_mb'] . ' MB', 46) . "║\n";
    echo "║                                                            ║\n";
    echo "║ Last Updated: " . str_pad(date('Y-m-d H:i:s'), 42) . "║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo PHP_EOL;
} else {
    $monitor->display();
}
