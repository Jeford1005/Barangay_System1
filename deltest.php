<?php
$cj = 'C:/Xampp/htdocs/BARANGAY_MANAGEMENT1/cj.txt';
@unlink($cj);
function get($url){ global $cj; $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_COOKIEJAR=>$cj,CURLOPT_COOKIEFILE=>$cj,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true]); $h=curl_exec($ch); curl_close($ch); return $h; }
function post($url,$data){ global $cj; $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_COOKIEJAR=>$cj,CURLOPT_COOKIEFILE=>$cj,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true]); $h=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$code,$h]; }
get('http://localhost/BARANGAY_MANAGEMENT/login.php');
post('http://localhost/BARANGAY_MANAGEMENT/login.php',http_build_query(['username'=>'admin','password'=>'admin@123']));
$html=get('http://localhost/BARANGAY_MANAGEMENT/residents.php');
preg_match('/csrf_token"[^>]*value="([^"]+)"/',$html,$m);
$token=$m[1]??'NONE';
echo "token=[$token]\n";
$p=new PDO("mysql:host=127.0.0.1;dbname=barangay_bidduang_db","root","");
$id=$p->query("SELECT id FROM residents LIMIT 1")->fetchColumn();
$before=$p->query("SELECT COUNT(*) FROM residents")->fetchColumn();
echo "id=$id before=$before\n";
list($code,$body)=post('http://localhost/BARANGAY_MANAGEMENT/residents.php',http_build_query(['action'=>'delete','id'=>$id,'csrf_token'=>$token]));
$after=$p->query("SELECT COUNT(*) FROM residents")->fetchColumn();
echo "delete POST http=$code body_len=".strlen($body)." after=$after deleted=".($before-$after)."\n";
echo "body_head: ".substr($body,0,200)."\n";
@unlink($cj);
