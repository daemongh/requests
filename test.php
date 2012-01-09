<?php

include 'requests.php';

echo "<pre>";

$req = new requests();
$res = $req->get('http://zombo.com');
print_r($res);