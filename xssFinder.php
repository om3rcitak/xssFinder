<?php

##########################################################################
##                                                                      ##
##                           xssFinder                                  ##
##          coded by om3rcitak - www.omercitak.com - @om3rcitak         ##
##                  github.com/Om3rCitak/xssFinder                      ##
##                                                                      ##
##########################################################################

include('simple_html_dom.php');


/* variables */


$domain = makeURL($argv[1]);
$scanned = [];
$host = parse_url($domain, PHP_URL_HOST);
$links = getLinks($domain);
$forms = getForms($links);
$key = substr(uniqid(), 0, 8);
$chars = ["'", '"', "<", ">"];
foreach ($chars as $char)
    $payloads[] = $key . $char;
$payload = implode($payloads);


/* attack */


attack($forms);


/* functions */


/**
 * @param $forms
 */
function attack($forms)
{
    foreach ($forms as $form) {
        $fields = getFields($form["inputs"]);
        $action = $form["action"];
        $method = $form["method"];

        $response = request($action, $method, $fields);
        $check = json_decode(xssCheck($response), true);

        if ($check["result"] == "yes")
            echo printDetail($action, 'post', $check["patterns"], $fields);

    }
}

/**
 * @param $action
 * @param $method
 * @param $patterns
 * @param $parameters
 * @return string
 */
function printDetail($action, $method, $patterns, $parameters)
{
    global $chars;

    $result = PHP_EOL . str_repeat('-', 70) . PHP_EOL;
    $result .= "Action => " . $action . PHP_EOL;
    $result .= "Method => " . $method . PHP_EOL;
    $result .= "Parameters => " . implode(', ', array_keys($parameters)) . PHP_EOL;
    $result .= "Unencoded Char(s) => ";
    foreach ($patterns as $pattern)
        $result .= $chars[$pattern] . ", ";
    $result .= PHP_EOL . str_repeat('-', 70) . PHP_EOL;

    return $result;
}

/**
 * @param $response
 * @return string
 */
function xssCheck($response)
{
    global $payloads;

    $patterns = [];
    foreach ($payloads as $key => $value)
        if (strpos($response, $value))
            $patterns[] = $key;

    if (count($patterns) > 0)
        $result = json_encode([
            'result' => 'yes',
            'patterns' => $patterns
        ]);
    else
        $result = json_encode([
            'result' => 'no'
        ]);

    return $result;
}

/**
 * @param $inputs
 * @return array
 */
function getFields($inputs)
{
    global $payload;
    $fields = [];
    foreach ($inputs as $input) {
        $fields[$input] = $payload;
    }

    return $fields;
}

/**
 * @param $links
 * @return array
 */
function getForms($links)
{
    $forms = [];

    foreach ($links as $link) {

        $str = file_get_contents($link);
        preg_match_all("#form(.*?)#", $str, $find);

        if (count($find[1]) > 0) {
            $html = str_get_html($str);
            foreach ($html->find('form') as $e) {
                $inputs = [];
                $e->find('input');

                foreach ($e->find('input') as $a)
                    if ($a->name)
                        $inputs[] = $a->name;

                $action = $e->action;
                if (empty($e->action) || !strpos($action, '://')) {
                    $action = $link;
                }

                $method = $e->method;
                if (!isset($e->method) || empty($e->method)) {
                    $method = "get";
                }

                $forms[] = [
                    'action' => $action,
                    'method' => $method,
                    'inputs' => $inputs
                ];
            }
        }

    }

    return array_unique($forms, SORT_REGULAR);
}

/**
 * @param $link
 * @return bool
 */
function getLinksRegex($link)
{
    $html = file_get_contents($link);
    preg_match_all('/<a\s+href=["\']([^"\']+)["\']/i', $html, $links, PREG_PATTERN_ORDER);

    if (count($links[1]) > 0)
        return $links[1];

    return false;
}

/**
 * @param $link
 * @return string
 */
function makeURL($link)
{
    $parse = parse_url($link);

    if (!isset($parse["scheme"]))
        $link = 'http://' . $link;

    if (!isset($parse["path"]) && substr($link, -1, 1) != '/')
        $link .= '/';

    return $link;
}

/**
 * @param $links
 * @param $link
 */
function refactorLinks(&$links, $link)
{
    global $host;

    $i = 0;
    foreach ($links as $l) {
        if (strpos($l, $host) === false) {
            $links[$i] = $link . '/' . $l;
        }
        $i++;
    }
}

/**
 * @param $links
 * @param $link
 */
function getLinksRecursive(&$links, $link)
{
    global $scanned, $host, $domain;

    $link = makeURL($link);

    if (!array_search($link, $scanned)) {
        $new_links = getLinksRegex($link);
        if ($new_links) {
            refactorLinks($new_links, $link);
            foreach ($new_links as $l) {
                getLinksRecursive($new_links, $l);
            }
            $links = array_merge($links, $new_links);
            $scanned[] = $link;
        }
    }

}

/**
 * @param $link
 * @return array
 */
function getLinks($link)
{
    $links = [
        $link
    ];

    getLinksRecursive($links, $link);

    return array_unique($links);
}

/**
 * @param $domain
 * @param $method
 * @param array $parameters
 * @return mixed
 */
function request($domain, $method, $parameters = [])
{
    $parameters_string = http_build_query($parameters);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    if ($method == "post") {
        curl_setopt($ch, CURLOPT_URL, $domain);
        curl_setopt($ch, CURLOPT_POST, count($parameters));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters_string);
    } else
        curl_setopt($ch, CURLOPT_URL, $domain . '?' . $parameters_string);

    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
}