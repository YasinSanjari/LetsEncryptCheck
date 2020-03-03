#!/usr/local/cpanel/3rdparty/bin/php
<?php
ini_set('display_errors', 'On');
error_reporting(E_ALL);

shell_exec("/usr/bin/yum install -y letsencrypt");

$domains = shell_exec("cat /etc/localdomains");
$domains = explode("\n", $domains);
$domainsNeeds = [];
$mc = curl_multi_init();
for ($thread_no = 0; $thread_no < count($domains); $thread_no++) {
    $domain = $domains[$thread_no];
    $c[$thread_no] = curl_init();
    curl_setopt($c[$thread_no], CURLOPT_URL, "https://checkhost.unboundtest.com/checkhost");
    curl_setopt($c[$thread_no], CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c[$thread_no], CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($c[$thread_no], CURLOPT_TIMEOUT, 30);
    curl_setopt($c[$thread_no], CURLOPT_POST, 1);
    curl_setopt($c[$thread_no], CURLOPT_POSTFIELDS,"fqdn=$domain");
    curl_multi_add_handle($mc, $c[$thread_no]);
}

do {
    while (($execrun = curl_multi_exec($mc, $running)) == CURLM_CALL_MULTI_PERFORM);
    if ($execrun != CURLM_OK) break;
    while ($done = curl_multi_info_read($mc)) {
        $info = curl_getinfo($done['handle']);
        $content = curl_multi_getcontent($done['handle']);
        if (strpos($content, 'needs renewal') !== false) {
            $domainsNeeds[] = trim($domains[array_search($done['handle'], $c)]);
        }
        
        curl_multi_remove_handle($mc, $done['handle']);
    }
} while ($running);

curl_multi_close($mc);

foreach($domainsNeeds as $domain){
    $user = trim(shell_exec("/scripts/whoowns $domain"));
    $home = null;
    if(is_dir("/home/$user/public_html")){
        $home = "/home/$user/public_html";
    } else if(is_dir("/home1/$user/public_html")){
        $home = "/home1/$user/public_html";
    } else if(is_dir("/home2/$user/public_html")){
        $home = "/home2/$user/public_html";
    } else if(is_dir("/home3/$user/public_html")){
        $home = "/home3/$user/public_html";
    } else if(is_dir("/home4/$user/public_html")){
        $home = "/home4/$user/public_html";
    }
    if(!$home){
        echo 'No home directory found for domain ' . $domain, PHP_EOL;
        continue;
    }
    $cmd = "/usr/bin/letsencrypt certonly --webroot -w $home --agree-tos -n --email admin@$domain -d $domain 2>&1";
    $result = shell_exec($cmd);
    if(stripos($result, 'Congratulations!') !== false || stripos($result, 'Cert not yet due for renewal') !== false){
        $cabundle = urlencode(file_get_contents("/etc/letsencrypt/live/$domain/chain.pem"));
        $crt = urlencode(file_get_contents("/etc/letsencrypt/live/$domain/cert.pem"));
        $key = urlencode(file_get_contents("/etc/letsencrypt/live/$domain/privkey.pem"));
        $result = shell_exec("/usr/bin/cpapi2 --user=$user SSL installssl cabundle=$cabundle crt=$crt domain=$domain key=$key 2>&1");
        if(stripos($result, 'successfully') !== false){
            echo 'SSL has been successfully installed for domain ' . $domain, PHP_EOL;
        } else {
            echo 'SSL renew failed-type2 to domain ' . $domain, PHP_EOL;
        }
    } else {
        echo 'SSL renew failed to domain ' . $domain, PHP_EOL;
    }
}

shell_exec("/scripts/restartsrv_httpd");
