<?php
// Generated corpus integration; start a disposable OpenSearch instance and ingest-demo first.
require_once __DIR__.'/../api/utils/OpenSearchClient.php';
$client=new OpenSearchClient();
$first=$client->search('alpha',2);
if (count($first['results'])!==2 || !$first['next_search_after']) throw new RuntimeException('First page failed');
$second=$client->search('alpha',2,0,false,$first['next_search_after']);
if (count($second['results'])!==2 || $second['results'][0]['content']===$first['results'][0]['content']) throw new RuntimeException('Pagination returned first-page content');
$repeat=$client->search('alpha',2,0,false,$first['next_search_after']);
if ($repeat['results']!==$second['results']) throw new RuntimeException('Cache changed the page');
$zero=$client->search('synthetic-no-such-keyword',2);
if (count($zero['results'])!==0) throw new RuntimeException('Zero match query failed');
echo "PASS: generated ingestion, pagination, repeat/cache consistency and zero matches.\n";
