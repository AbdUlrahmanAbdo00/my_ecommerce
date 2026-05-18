<?php

class ContinuousResourceMonitor
{
    private bool $isWindows;
    private array $cpuBaseline = [];
    private array $samples = [];
    private int $duration;
    private int $interval;
    private int $sampleCount = 0;

    public function __construct(int $duration = 300, int $interval = 2)
    {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $this->duration = max($duration, 10);
        $this->interval = max($interval, 1);
    }

    public function start(): void
    {
        $startTime = time();
        $endTime = $startTime + $this->duration;

        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║          CONTINUOUS RESOURCE MONITOR - STARTED            ║\n";
        echo "╠════════════════════════════════════════════════════════════╣\n";
        echo "║ Duration: {$this->duration} seconds                           \n";
        echo "║ Interval: {$this->interval} seconds                            \n";
        echo "║ Press Ctrl+C to stop                                       \n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n";

        // Handle Ctrl+C gracefully
        pcntl_signal(SIGINT, function () {
            $this->displayFinalReport();
            exit(0);
        });

        while (time() < $endTime) {
            $snapshot = $this->getSnapshot();
            $this->samples[] = $snapshot;
            $this->sampleCount++;

            $this->displaySnapshot($snapshot);

            $elapsed = time() - $startTime;
            $remaining = $endTime - time();

            if ($remaining > 0) {
                sleep($this->interval);
            }
        }

        $this->displayFinalReport();
    }

    private function getSnapshot(): array
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
            'timestamp' => microtime(true),
            'php' => $php,
            'mysql' => $mysql,
            'redis' => $redis,
            'total' => [
                'cpu' => round($totalCpu, 2),
                'ram_mb' => round($totalRam, 2),
            ],
        ];
    }

    private function getPhpResources(): array
    {
        if ($this->isWindows) {
            return $this->getPhpResourcesWindows();
        }

        return $this->getPhpResourcesLinux();
    }

    private function getPhpResourcesWindows(): array
    {
        $command = 'powershell -NoProfile -Command "Get-Process -Name \'php\' | Select-Object Id, @{Name=\'CPU\';Expression={$_.CPU}}, @{Name=\'WorkingSet64\';Expression={$_.WorkingSet64}} | ConvertTo-Json"';
        $output = shell_exec($command);

        if (!$output) {
            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'processes' => 0,
                'status' => 'Not running',
            ];
        }

        $processes = json_decode($output, true);

        if (!is_array($processes)) {
            $processes = [$processes];
        }

        $ramBytes = 0.0;
        $processCount = 0;
        $currentCpu = [];

        foreach ($processes as $process) {
            if (!is_array($process)) {
                continue;
            }

            $processCount++;
            $pid = (string) ($process['Id'] ?? uniqid('php', true));
            $ramBytes += (float) ($process['WorkingSet64'] ?? 0);
            $currentCpu[$pid] = (float) ($process['CPU'] ?? 0);
        }

        $cpuPercent = $this->calculateCpuPercent('php', $currentCpu);

        return [
            'cpu' => round($cpuPercent, 2),
            'ram_mb' => round($ramBytes / (1024 * 1024), 2),
            'processes' => $processCount,
            'status' => $processCount > 0 ? 'Running' : 'Not running',
        ];
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

        $lines = explode("\n", trim($output));
        $totalCpu = 0.0;
        $totalRam = 0.0;

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
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
            'status' => count($lines) > 0 ? 'Running' : 'Not running',
        ];
    }

    private function getMysqlResources(): array
    {
        if ($this->isWindows) {
            $command = 'powershell -NoProfile -Command "Get-Process -Name \'mysqld\' -ErrorAction SilentlyContinue | Select-Object @{Name=\'CPU\';Expression={$_.CPU}}, @{Name=\'WorkingSet64\';Expression={$_.WorkingSet64}} | ConvertTo-Json"';
            $output = shell_exec($command);

            if (!$output) {
                return [
                    'cpu' => 0,
                    'ram_mb' => 0,
                    'status' => 'Not running',
                ];
            }

            $data = json_decode($output, true);

            if (is_array($data) && isset($data['WorkingSet64'])) {
                return [
                    'cpu' => round((float) ($data['CPU'] ?? 0), 2),
                    'ram_mb' => round((float) ($data['WorkingSet64'] ?? 0) / (1024 * 1024), 2),
                    'status' => 'Running',
                ];
            }

            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'status' => 'Not running',
            ];
        }

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
            return [
                'cpu' => round((float) $parts[2], 2),
                'ram_mb' => round((float) $parts[5] / 1024, 2),
                'status' => 'Running',
            ];
        }

        return [
            'cpu' => 0,
            'ram_mb' => 0,
            'status' => 'Not running',
        ];
    }

    private function getRedisResources(): array
    {
        if ($this->isWindows) {
            $command = 'powershell -NoProfile -Command "Get-Process -Name \'redis-server\' -ErrorAction SilentlyContinue | Select-Object @{Name=\'CPU\';Expression={$_.CPU}}, @{Name=\'WorkingSet64\';Expression={$_.WorkingSet64}} | ConvertTo-Json"';
            $output = shell_exec($command);

            if (!$output) {
                return [
                    'cpu' => 0,
                    'ram_mb' => 0,
                    'status' => 'Not running',
                ];
            }

            $data = json_decode($output, true);

            if (is_array($data) && isset($data['WorkingSet64'])) {
                return [
                    'cpu' => round((float) ($data['CPU'] ?? 0), 2),
                    'ram_mb' => round((float) ($data['WorkingSet64'] ?? 0) / (1024 * 1024), 2),
                    'status' => 'Running',
                ];
            }

            return [
                'cpu' => 0,
                'ram_mb' => 0,
                'status' => 'Not running',
            ];
        }

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
            return [
                'cpu' => round((float) $parts[2], 2),
                'ram_mb' => round((float) $parts[5] / 1024, 2),
                'status' => 'Running',
            ];
        }

        return [
            'cpu' => 0,
            'ram_mb' => 0,
            'status' => 'Not running',
        ];
    }

    private function calculateCpuPercent(string $label, array $currentCpu): float
    {
        $key = strtolower($label);
        $now = microtime(true);

        if (!isset($this->cpuBaseline[$key])) {
            $this->cpuBaseline[$key] = [
                'timestamp' => $now,
                'cpu' => $currentCpu,
            ];

            return 0.0;
        }

        return 0.0;
    }

    private function displaySnapshot(array $snapshot): void
    {
        $php = $snapshot['php'];
        $mysql = $snapshot['mysql'];
        $redis = $snapshot['redis'];
        $total = $snapshot['total'];

        $phpCpu = is_numeric($php['cpu']) ? $php['cpu'] . '%' : $php['cpu'];
        $mysqlCpu = is_numeric($mysql['cpu']) ? $mysql['cpu'] . '%' : $mysql['cpu'];
        $redisCpu = is_numeric($redis['cpu']) ? $redis['cpu'] . '%' : $redis['cpu'];
        $totalCpu = is_numeric($total['cpu']) ? $total['cpu'] . '%' : $total['cpu'];

        echo "Sample #{$this->sampleCount} | Time: " . date('H:i:s') . "\n";
        echo "─────────────────────────────────────────────────────────────\n";
        echo "PHP:   CPU: {$phpCpu} | RAM: {$php['ram_mb']} MB | Status: {$php['status']}\n";
        echo "MySQL: CPU: {$mysqlCpu} | RAM: {$mysql['ram_mb']} MB | Status: {$mysql['status']}\n";
        echo "Redis: CPU: {$redisCpu} | RAM: {$redis['ram_mb']} MB | Status: {$redis['status']}\n";
        echo "TOTAL: CPU: {$totalCpu} | RAM: {$total['ram_mb']} MB\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    }

    private function displayFinalReport(): void
    {
        if (empty($this->samples)) {
            return;
        }

        $cpuValues = [];
        $ramValues = [];

        foreach ($this->samples as $sample) {
            if (isset($sample['total']['cpu']) && is_numeric($sample['total']['cpu'])) {
                $cpuValues[] = (float) $sample['total']['cpu'];
            }
            if (isset($sample['total']['ram_mb']) && is_numeric($sample['total']['ram_mb'])) {
                $ramValues[] = (float) $sample['total']['ram_mb'];
            }
        }

        $avgCpu = $cpuValues !== [] ? round(array_sum($cpuValues) / count($cpuValues), 2) : 0;
        $maxCpu = $cpuValues !== [] ? round(max($cpuValues), 2) : 0;
        $minCpu = $cpuValues !== [] ? round(min($cpuValues), 2) : 0;

        $avgRam = $ramValues !== [] ? round(array_sum($ramValues) / count($ramValues), 2) : 0;
        $maxRam = $ramValues !== [] ? round(max($ramValues), 2) : 0;
        $minRam = $ramValues !== [] ? round(min($ramValues), 2) : 0;

        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║              FINAL REPORT - MONITORING COMPLETE            ║\n";
        echo "╠════════════════════════════════════════════════════════════╣\n";
        echo "║ Total Samples: " . str_pad($this->sampleCount, 45) . "║\n";
        echo "║ Duration: " . str_pad($this->duration . ' seconds', 48) . "║\n";
        echo "║ Interval: " . str_pad($this->interval . ' seconds', 48) . "║\n";
        echo "║                                                            ║\n";
        echo "║ 📊 CPU USAGE (%)                                           ║\n";
        echo "║    Average: " . str_pad($avgCpu . '%', 47) . "║\n";
        echo "║    Maximum: " . str_pad($maxCpu . '%', 47) . "║\n";
        echo "║    Minimum: " . str_pad($minCpu . '%', 47) . "║\n";
        echo "║                                                            ║\n";
        echo "║ 💾 RAM USAGE (MB)                                          ║\n";
        echo "║    Average: " . str_pad($avgRam . ' MB', 45) . "║\n";
        echo "║    Maximum: " . str_pad($maxRam . ' MB', 45) . "║\n";
        echo "║    Minimum: " . str_pad($minRam . ' MB', 45) . "║\n";
        echo "║                                                            ║\n";
        echo "║ Last Updated: " . str_pad(date('Y-m-d H:i:s'), 42) . "║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }
}

// Parse command line arguments
$duration = isset($argv[1]) ? (int) $argv[1] : 300; // Default 5 minutes
$interval = isset($argv[2]) ? (int) $argv[2] : 2;   // Default 2 seconds

$monitor = new ContinuousResourceMonitor($duration, $interval);
$monitor->start();
