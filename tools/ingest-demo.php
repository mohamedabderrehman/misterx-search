<?php
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../api/config.php';
require_once __DIR__.'/../vendor/autoload.php';
$client=OpenSearch\ClientBuilder::create()->setHosts(['http://'.OPENSEARCH_HOST.':'.OPENSEARCH_PORT])->build();
if (!$client->indices()->exists(['index'=>OPENSEARCH_INDEX])) $client->indices()->create(['index'=>OPENSEARCH_INDEX,'body'=>['settings'=>['number_of_shards'=>1,'number_of_replicas'=>0],'mappings'=>['properties'=>['content'=>['type'=>'text']]]]]);
$body=[];
foreach (file(__DIR__.'/../fixtures/generated.jsonl',FILE_IGNORE_NEW_LINES) as $id=>$line) {
 $body[]=['index'=>['_index'=>OPENSEARCH_INDEX,'_id'=>'synthetic-'.$id]];$body[]=json_decode($line,true,512,JSON_THROW_ON_ERROR);
}
$result=$client->bulk(['refresh'=>true,'body'=>$body]);
if ($result['errors']) throw new RuntimeException('Synthetic ingestion failed');
echo "Indexed 100 generated demonstration records.\n";
