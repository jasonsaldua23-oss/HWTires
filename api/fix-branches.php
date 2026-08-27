<?php
// Fix missing branches and clean up utility files
session_start();

// Check if user is admin
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized. Admin access required.');
}

require_once '../includes/config.php';

try {
    // Check if branches already exist
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM branches");
    $result = $stmt->fetch();
    
    if ($result['count'] == 0) {
        // Insert 3 main branches
        $insertBranches = <<<SQL
        INSERT INTO branches (name, location, branch_supervisor, contact_number, email, status) 
        VALUES 
        ('Main Branch', 'Downtown Center', 'James Morrison', '555-0101', 'main@hwtires.local', 'active'),
        ('North Branch', 'North District', 'Maria Santos', '555-0102', 'north@hwtires.local', 'active'),
        ('East Branch', 'East Industrial Zone', 'David Chen', '555-0103', 'east@hwtires.local', 'active')
        SQL;
        
        $pdo->exec($insertBranches);
        echo "<div style='background: #e8f5e9; border: 1px solid #4caf50; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
        echo "<strong>✅ Success:</strong> 3 branches inserted into database<br>";
        echo "- Main Branch (Downtown Center)<br>";
        echo "- North Branch (North District)<br>";
        echo "- East Branch (East Industrial Zone)<br>";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3e0; border: 1px solid #ff9800; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
        echo "<strong>ℹ️ Info:</strong> Branches already exist (" . $result['count'] . " branches found)<br>";
        echo "</div>";
    }
    
    // Verify branches
    $stmt = $pdo->query("SELECT id, name, location FROM branches ORDER BY id");
    $branches = $stmt->fetchAll();
    
    echo "<h3>Current Branches:</h3>";
    echo "<table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>";
    echo "<tr style='background: #f5f5f5;'><th style='padding: 10px; text-align: left; border: 1px solid #ddd;'>ID</th><th style='padding: 10px; text-align: left; border: 1px solid #ddd;'>Name</th><th style='padding: 10px; text-align: left; border: 1px solid #ddd;'>Location</th></tr>";
    foreach ($branches as $branch) {
        echo "<tr>";
        echo "<td style='padding: 10px; border: 1px solid #ddd;'>" . $branch['id'] . "</td>";
        echo "<td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($branch['name']) . "</td>";
        echo "<td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($branch['location']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<div style='background: #ffebee; border: 1px solid #f44336; padding: 15px; margin: 10px 0; border-radius: 4px; color: #c62828;'>";
    echo "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>
