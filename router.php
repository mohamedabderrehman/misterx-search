<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (preg_match('#^/(?:uploads/projects|docs|tools)/#',$path) || preg_match('/\.(sql|env|sqlite|md)$/i',$path)) {http_response_code(403);exit;}
if (is_file(__DIR__.$path)) return false;
if (str_starts_with($path,'/api/')) {
 $endpoint=__DIR__.rtrim($path,'/').'/index.php';
 if (is_file($endpoint)) {require $endpoint;return true;}
 return false;
}
$page=__DIR__.rtrim($path,'/').'.html';
if (is_file($page)) {readfile($page);return true;}
readfile(__DIR__.'/index.html');
