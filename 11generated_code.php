<?php
// Check if Node.js is installed and get its version
function checkNodeVersion() {
    $nodePath = 'node';
    
    // Try to find node in PATH
    $output = shell_exec($nodePath . ' --version 2>&1');
    
    if ($output !== null) {
        echo "Node.js is installed!\n";
        echo "Version: " . trim($output) . "\n";
    } else {
        echo "Node.js is not installed or not found in PATH.\n";
        
        // Try alternative paths
        $alternativePaths = [
            '/usr/bin/node',
            '/usr/local/bin/node',
            '/opt/node/bin/node'
        ];
        
        foreach ($alternativePaths as $path) {
            if (file_exists($path)) {
                $output = shell_exec($path . ' --version 2>&1');
                if ($output !== null) {
                    echo "Found Node.js at: " . $path . "\n";
                    echo "Version: " . trim($output) . "\n";
                    return;
                }
            }
        }
    }
}

checkNodeVersion();
?>