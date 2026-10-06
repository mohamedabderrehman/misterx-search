<?php
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require_once __DIR__.'/../api/config.php';
$password=getenv('DEMO_PASSWORD');
if (!$password || strlen($password)<12) throw new RuntimeException('Set DEMO_PASSWORD to at least 12 characters; use a fresh demo database.');
$conn=getDBConnection();
foreach (['database.sql','database_support_tickets.sql','database_search_jobs.sql'] as $file) {
 if (!$conn->multi_query(file_get_contents(__DIR__.'/../'.$file))) throw new RuntimeException($conn->error);
 do {if ($result=$conn->store_result()) $result->free();} while ($conn->more_results() && $conn->next_result());
 if ($conn->errno) throw new RuntimeException($conn->error);
}
$hash=password_hash($password,PASSWORD_DEFAULT);
foreach (['admin','client','other'] as $name) {
 $email=$name.'@example.test';$role=$name==='admin'?'admin':'normal';
 $stmt=$conn->prepare('INSERT INTO users (email,username,password_hash,role) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)');
 $stmt->bind_param('ssss',$email,$name,$hash,$role);$stmt->execute();
}
echo "Search schema and synthetic accounts ready.\n";
