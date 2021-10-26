<?php
/*
 * Copyright (C) 2021 omegazero.org
 *
 * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
 * If a copy of the MPL was not distributed with this file, You can obtain one at https://mozilla.org/MPL/2.0/.
 *
 * Covered Software is provided under this License on an "as is" basis, without warranty of any kind,
 * either expressed, implied, or statutory, including, without limitation, warranties that the Covered Software
 * is free of defects, merchantable, fit for a particular purpose or non-infringing.
 * The entire risk as to the quality and performance of the Covered Software is with You.
 */
declare(strict_types=1);

require_once("config.php");


$VERSION = "2.2.0";


function request(string $url){
	global $VERSION;
	$startTime = microtime(true);

	$cacheKey = "res:" . $url;
	$cacheEntry = apcu_fetch($cacheKey);
	if($cacheEntry){
		$resource = $cacheEntry;
	}else{
		$resource = new stdClass();
		$curl = curl_init($url);
		$setopt_array = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array(
				"User-Agent: omz-docs/" . $VERSION . " robot (https://docs.omegazero.org/)"
			),
			CURLOPT_HEADER => false,
			CURLOPT_TIMEOUT => 5
		);
		curl_setopt_array($curl, $setopt_array);
		$resource->data = curl_exec($curl);
		$resource->statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);

		apcu_store($cacheKey, $resource, 15);
	}

	$res = clone $resource;
	$res->timeMs = (microtime(true) - $startTime) * 1000;
	return $res;
}

function safeMeta(string $str) : string{
	return htmlspecialchars($str, ENT_QUOTES);
}

function metadataAvailable(?object $metadata, string $name, string $type) : bool{
	return !is_null($metadata) && isset($metadata->$name) && gettype($metadata->$name) === $type;
}

function resourceMetadataAvailable(string $name, string $type) : bool{
	global $resource;
	return !is_null($resource) && metadataAvailable($resource->metadata, $name, $type);
}


function errormsg(string $str){
	echo '<span style="color:red;font-weight:bold;font-style: italic;">' . htmlspecialchars($str) . '</span>';
}

function errormsgconf(string $str){
	errormsg('Configuration Error: ' . $str);
}

function str_starts_with(string $str, string $prefix) : bool{
	$prefixLen = strlen($prefix);
	return strlen($str) > $prefixLen && substr($str, 0, $prefixLen) == $prefix;
}


interface DataSource{

	public function getResource(string $path, string $versionName, string $rootDir) : ?object;
}


function uncaughtException($exception){
	error_log("Uncaught Exception: " . $exception);
	ob_clean();
	http_response_code(500);
	echo "Internal Server Error";
	ob_end_flush();
}

set_exception_handler('uncaughtException');


ob_start();

$versionName = "current";
$versionNameQuery = "";
if(isset($_GET["version"])){
	$versionName = $_GET["version"];
	$versionNameQuery = "?version=" . $versionName;
}

$contentOnly = false;
if(isset($_GET["contentOnly"]))
	$contentOnly = !!$_GET["contentOnly"];

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$rootDir = dirname($_SERVER['SCRIPT_NAME']);
$subpathStr = substr($requestPath, strlen($rootDir) + 1);

$confinitError = false;

$redirectDest = false;

function errormsginitconf(?string $str){
	global $confinitError;
	if(is_null($str))
		$confinitError = false;
	else if($confinitError === false)
		$confinitError = $str;
	else
		$confinitError .= " / " . $str;
}

function redirectTo(string $dest){
	global $redirectDest;
	$redirectDest = $dest;
}


$searchTime = 0;
$fetchTime = 0;
$resource = null;
foreach($datasources as $dsName){
	$dsClass = require_once("lib/datasource/" . $dsName . ".php");
	$dsArgs = array();
	if(is_array($datasourceArgs) && isset($datasourceArgs[$dsName]) && is_array($datasourceArgs[$dsName]))
		$dsArgs = $datasourceArgs[$dsName];
	$dsHandler = new $dsClass($dsArgs);
	$startTime = microtime(true);
	if($dsHandler instanceof DataSource){
		$resource = $dsHandler->getResource($subpathStr, $versionName, $rootDir);
	}else
		error_log($dsClass . " is not a DataSource");
	$dsTime = (microtime(true) - $startTime) * 1000;
	if(!is_null($resource) || $confinitError || $redirectDest){
		$fetchTime = $dsTime;
		break;
	}else
		$searchTime += $dsTime;
}
$resourceValid = !is_null($resource);


?>

<?php if(!$contentOnly) : ?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<link rel="stylesheet" type="text/css" href="/common/docs.css" />
		<script src="/common/docs.js"></script>
		<meta name="viewport" content="width=device-width, initial-scale=1" />

<?php

if(resourceMetadataAvailable("siteTitle", "string"))
	echo "<title>" . safeMeta($resource->metadata->siteTitle) . "</title>";
else if(resourceMetadataAvailable("title", "string"))
	echo "<title>" . safeMeta($resource->metadata->title) . "</title>";
else
	echo "<title>omz-docs</title>";

if(resourceMetadataAvailable("siteIcon", "string"))
	echo '<link rel="icon" href="' . safeMeta($resource->metadata->siteIcon) . '" />';
else if(resourceMetadataAvailable("icon", "string"))
	echo '<link rel="icon" href="' . safeMeta($resource->metadata->icon) . '" />';

if(resourceMetadataAvailable("meta", "object")){
	foreach($resource->metadata->meta as $name => $value){
		echo "<meta name=\"" . safeMeta($name) . "\" content=\"" . safeMeta($value) . "\" />";
	}
}else if(!$resourceValid){
	echo "<meta name=\"description\" content=\"This is not a valid documentation page\" />";
}

if($resourceValid){
	if(isset($resource->rootDir))
		$docsRootDir = $resource->rootDir;
	else
		$docsRootDir = $requestPath;
	echo "<meta name=\"docsRoot\" content=\"" . $docsRootDir . "\" />";
}

?>
<meta name="omz-proxy-ssi" />
	</head>
	<body>

<header class="bar topbar">
	<label id="hideSidebarLabel" for="hideSidebar">
		<svg>
			<rect class="ibar ibar1" />
			<rect class="ibar ibar2" />
			<rect class="ibar ibar3" />
		</svg>
	</label>

<?php

if(resourceMetadataAvailable("icon", "string"))
	echo '<img id="logo" src="' . safeMeta($resource->metadata->icon) . '" class="logo" alt="Documentation logo" />';

if(resourceMetadataAvailable("title", "string"))
	echo '<span id="title" class="title">' . safeMeta($resource->metadata->title) . '</span>';
else if($resourceValid)
	errormsgconf("Missing title");


if($resourceValid){
	echo '<select id="versionSelector">';
	foreach($resource->versions as $name){
		echo '<option value="' . urlencode($name) . '" ' . ($versionName == $name ? ' selected' : '') . '>' . htmlspecialchars($name) . '</option>';
	}
	echo '</select>';
}

?>

</header>
<input id="hideSidebar" type="checkbox" checked />
<nav class="bar sidebar">

<?php

function addSidebarEntry(object $entry, int $depth, string $base, bool $deep){
	global $docsRootDir;
	global $versionNameQuery;
	$maxDepth = $deep ? 10 : 3;
	if($depth > $maxDepth){
		errormsgconf("Maximum depth exceeded: " . $maxDepth);
		return;
	}
	if($entry->type == "line"){
		echo '<div class="line"></div>';
	}else if($entry->type == "link"){
		echo '<a id="sidebar_entry_' . str_replace("/", "-", $base) . $entry->id . '" class="sidebar-entry sidebar-entry-' . ($deep ? 'deep' : ('l' . $depth))
			. ($entry->selected ? ' sidebar-entry-selected' : '') . ' sidebar-regularentry" style="--depth: ' . ($depth - 1) . ';" href="' . $docsRootDir . $base . $entry->id
			. $versionNameQuery . '">' . $entry->name . '</a>';
	}
}

function addSidebarObject(array $content, int $depth, string $base, bool $deep){
	foreach($content as $entry){
		if($entry->type == "category"){
			$id = 'sidebar_collapsible_' . str_replace("/", "-", $base) . $entry->id;
			echo '<a id="' . $id . '" class="sidebar-entry sidebar-entry-' . ($deep ? 'deep' : ('l' . $depth)) . ' sidebar-collapsible" style="--depth: ' . ($depth - 1) . ';">'
				. $entry->name . '</a><div id="' . $id . '_content">';
			addSidebarObject($entry->content, $depth + 1, $base . $entry->id . "/", $deep);
			echo '</div>';
		}else{
			addSidebarEntry($entry, $depth, $base, $deep);
		}
	}
}

if($resourceValid){
	if(isset($resource->sidebarContent) && gettype($resource->sidebarContent) == "array"){
		if(!isset($resource->sidebarDeep))
			$resource->sidebarDeep = false;
		addSidebarObject($resource->sidebarContent, 1, "/", !!$resource->sidebarDeep);
	}else{
		errormsgconf("sidebar content is missing or invalid");
	}
}else{ // empty div to push links div to bottom
	echo '<div></div>';
}


echo '<div class="links"><a href="https://docs.omegazero.org/">omz-docs v' . $VERSION . '</a>';
foreach($links as $name => $href){
	echo ' | <a href="' . $href . '">' . $name . '</a>';
}
echo '</div>';

?>

</nav>

<div id="mainModal"></div>
<main id="main">
	<div id="loadingBar"></div>
	<div id="mainContent">
<?php endif; ?>


<?php

if($confinitError){
	if(!$contentOnly)
		http_response_code(404);
	errormsgconf($confinitError);
}else if($redirectDest){
	header("Location: " . $redirectDest, true, 307);
	echo 'Moved to <a href="' . $redirectDest . '">' . $redirectDest . '</a>';
}else if(is_null($resource)){
	if(!$contentOnly)
		http_response_code(404);
	errormsg("Resource not found");
}else{
	$body = $resource->data;

	if($resource->markdown){
		$pd = null;
		if(file_exists("lib/ParsedownExtra.php")){
			require_once("lib/Parsedown.php");
			require_once("lib/ParsedownExtra.php");
			$pd = new ParsedownExtra();
		}else{
			require_once("lib/Parsedown.php");
			$pd = new Parsedown();
		}
		$body = $pd->text($body);
	}

	if($resource->purify){
		if(file_exists("lib/htmlpurifier/HTMLPurifier.standalone.php")){
			require_once("lib/htmlpurifier/HTMLPurifier.standalone.php");
			$htmlpurifierconfig = HTMLPurifier_Config::createDefault();
			if(is_null($htmlPurifierCacheDir)){
				$htmlpurifierconfig->set('Cache.DefinitionImpl', null);
			}else{
				$htmlpurifierconfig->set('Cache.SerializerPath', $htmlPurifierCacheDir);
			}
			$htmlpurifier = new HTMLPurifier($htmlpurifierconfig);
			$body = $htmlpurifier->purify($body);
		}
	}

	echo $body;
}

?>


<?php if(!$contentOnly) : ?>
	</div>
</main>

	</body>
</html>

<?php endif; ?>

<?php

header("Cache-Control: max-age=" . $cacheMaxAge);

$time = (microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000 - $fetchTime;
header("Server-Timing: docs-search;dur=" . round($searchTime) . ", docs-fetch;dur=" . round($fetchTime) . ", docs-page-gen;dur=" . round($time));

header("Content-Length: " . ob_get_length());
ob_end_flush();

?>
