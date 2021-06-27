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


$VERSION = "2.1.0";


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

function parseSubpath(string $path){
	global $sources;
	$res = new stdClass();

	$pathparts = explode("/", $path, 4);
	$plen = count($pathparts);
	if($plen < 2)
		return null;
	$res->owner = "";
	$res->name = "";
	$res->path = "";
	$res->rootDir = "";
	$sourceNum = intval($pathparts[0]);
	if($sourceNum > count($sources)){
		return null;
	}else if($sourceNum <= 0){
		$sourceNum = 1;
		$res->owner = $pathparts[0];
		$res->name = $pathparts[1];
		$res->rootDir = "/" . $res->owner . "/" . $res->name;
		if($plen > 2)
			$res->path = $pathparts[2];
		if($plen > 3)
			$res->path .= "/" . $pathparts[3];
	}else{
		if($plen < 3)
			return null;
		$res->owner = $pathparts[1];
		$res->name = $pathparts[2];
		$res->rootDir = "/" . $sourceNum . "/" . $res->owner . "/" . $res->name;
		if($plen > 3)
			$res->path = $pathparts[3];
	}
	if(strlen($res->owner) < 1 || strlen($res->name) < 1)
		return null;
	$res->sourceNum = $sourceNum;
	$res->source = $sources[$sourceNum];
	return $res;
}

function stringifySubpath(object $subpath) : string{
	if($subpath->sourceNum == 1)
		return $subpath->owner . "/" . $subpath->name . "/" . $subpath->path;
	else
		return $subpath->sourceNum . "/" . $subpath->owner . "/" . $subpath->name . "/" . $subpath->path;
}

function getFileFromSource(object $subpath, string $filename) : object{
	global $gitTag;
	if($gitTag == "current")
		return request(strtr($subpath->source["resourceUrl"], array("$(owner)" => $subpath->owner, "$(repository)" => $subpath->name, "$(file)" => $filename)));
	else
		return request(strtr($subpath->source["taggedResourceUrl"], array("$(owner)" => $subpath->owner, "$(repository)" => $subpath->name, "$(tag)" => $gitTag, "$(file)" => $filename)));
}

function getTagsFromSource(object $subpath) : object{
	return request(strtr($subpath->source["tagsUrl"], array("$(owner)" => $subpath->owner, "$(repository)" => $subpath->name)));
}

function safeMeta(string $str) : string{
	return htmlspecialchars($str, ENT_QUOTES);
}

function metadataAvailable(string $name, string $type) : bool{
	global $metadata;
	return !is_null($metadata) && isset($metadata->$name) && gettype($metadata->$name) === $type;
}


function errormsg(string $str){
	echo '<span style="color:red;font-weight:bold;font-style: italic;">' . htmlspecialchars($str) . '</span><br />';
}

function errormsgconf(string $str){
	errormsg('Configuration Error: ' . $str);
}

function str_starts_with(string $str, string $prefix) : bool{
	$prefixLen = strlen($prefix);
	return strlen($str) > $prefixLen && substr($str, 0, $prefixLen) == $prefix;
}


function nameToKey(string $name) : string{
	return strtr($name, " ", "_");
}

class Entry{

	public function __construct(?string $name, string $value){
		if(!is_null($name)){
			$this->name = $name;
			$this->key = nameToKey($name);
		}
		$p = explode(" ", $value);
		$this->filename = array_shift($p);
		if(count($p) > 0)
			$this->flags = $p;
		else
			$this->flags = array("resource");
	}

	public function hasFlag($fname) : bool{
		return in_array($fname, $this->flags);
	}
}

function getFilename(string $path) : ?string{
	global $metadata;
	static $aliasesSearched = array();
	if(metadataAvailable("content", "object")){
		$parts = explode("/", $path);
		$cur = $metadata->content;
		foreach($parts as $value){
			if(!is_object($cur)){
				$cur = null;
				break;
			}
			$cur_n = new stdClass();
			foreach($cur as $name => $v){
				$key = nameToKey($name);
				$cur_n->$key = $cur->$name;
			}
			$cur = $cur_n;
			if(isset($cur->$value))
				$cur = $cur->$value;
		}
		if(is_object($cur) && isset($cur->default)){
			$cur = $cur->default;
		}
		if(is_string($cur)){
			$entry = new Entry(null, $cur);
			if(str_starts_with($entry->filename, "@")){
				$alias = substr($entry->filename, 1);
				if(in_array($alias, $aliasesSearched)){
					errormsgconf("Loop in content aliases: " . $alias . " points back to itself");
					return null;
				}
				array_push($aliasesSearched, $alias);
				return getFilename($alias);
			}else if($entry->hasFlag("resource"))
				return $entry->filename;
		}
	}
	return null;
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

$fetchTime = 0;

$gitTag = "current";
$gitTagQuery = "";
if(isset($_GET["tag"])){
	$gitTag = $_GET["tag"];
	$gitTagQuery = "?tag=" . $gitTag;
}

$contentOnly = false;
if(isset($_GET["contentOnly"]))
	$contentOnly = !!$_GET["contentOnly"];

$rootDir = dirname($_SERVER['SCRIPT_NAME']);
$subpathStr = substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen($rootDir) + 1);
$subpath = parseSubpath($subpathStr);
$docsRootDir = null;

$metadata = null;
$metadataValid = false;
$contentFilename = null;

$confinitError = false;

function errormsginitconf(string $str){
	global $confinitError;
	if($confinitError === false)
		$confinitError = $str;
	else
		$confinitError .= " / " . $str;
}

function fetchMetadata(object $subpath){
	global $metaFileName; // defined in config.php
	global $fetchTime;
	global $metadata;
	global $metadataValid;
	global $contentFilename;
	// reset
	$metadata = null;
	$metadataValid = false;

	$metaRes = getFileFromSource($subpath, $metaFileName);
	$fetchTime += $metaRes->timeMs;
	if($metaRes->data !== false && $metaRes->statusCode === 200){
		$metadata = json_decode($metaRes->data, false, 8);
	}else if($metaRes->statusCode === 404){
		errormsginitconf("Metadata does not exist");
	}else if($metaRes->statusCode !== 200){
		errormsginitconf("Metadata request returned status " . $metaRes->statusCode);
	}else{
		errormsginitconf("Failed to fetch metadata");
	}

	if(is_null($metadata))
		errormsginitconf("Metadata is not valid JSON");

	$metadataVersion = 1;
	if(metadataAvailable("version", "integer"))
		$metadataVersion = $metadata->version;
	if(!is_null($metadata)){
		if($metadataVersion != 2)
			errormsginitconf("Unsupported metadata version: " . $metadataVersion);
		else
			$metadataValid = true;
	}

	$contentFilename = getFilename($subpath->path);
}

function parseRedirect(string $str, object $subpath) : bool{
	if(!preg_match("/^([a-zA-Z0-9\-_\.]+\/?){1,2}$/", $str))
		return false;
	$parts = explode("/", $str);
	if(count($parts) > 1){
		$subpath->owner = $parts[0];
		$subpath->name = $parts[1];
	}else{
		$subpath->name = $parts[0];
	}
	return true;
}

if(!is_null($subpath)){
	$docsRootDir = $rootDir . $subpath->rootDir;

	fetchMetadata($subpath);
	if($metadataValid){
		if(metadataAvailable("redirect", "string")){
			if(parseRedirect($metadata->redirect, $subpath)){
				header("Location: " . $rootDir . "/" . stringifySubpath($subpath), true, 307);
			}else{
				errormsginitconf("Redirect destination is invalid");
			}
		}else if(metadataAvailable("delegate", "string")){
			if(parseRedirect($metadata->delegate, $subpath)){
				fetchMetadata($subpath);
			}else{
				errormsginitconf("Delegate destination is invalid");
			}
		}
	}
}

?>

<?php if(!$contentOnly) : ?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<link rel="stylesheet" type="text/css" href="/common/docs.css" />
		<script src="/common/docs.js"></script>
		<meta name="viewport" content="width=device-width, initial-scale=1" />

<?php

if(metadataAvailable("siteTitle", "string"))
	echo "<title>" . safeMeta($metadata->siteTitle) . "</title>";
else if(metadataAvailable("title", "string"))
	echo "<title>" . safeMeta($metadata->title) . "</title>";
else
	echo "<title>omz-docs</title>";

if(metadataAvailable("siteIcon", "string"))
	echo '<link rel="icon" href="' . safeMeta($metadata->siteIcon) . '" />';
else if(metadataAvailable("icon", "string"))
	echo '<link rel="icon" href="' . safeMeta($metadata->icon) . '" />';

if(metadataAvailable("meta", "object")){
	foreach($metadata->meta as $name => $value){
		echo "<meta name=\"" . safeMeta($name) . "\" content=\"" . safeMeta($value) . "\" />";
	}
}else if(!$metadataValid){
	echo "<meta name=\"description\" content=\"This is not a valid documentation page\" />";
}

echo "<meta name=\"docsRoot\" content=\"" . $docsRootDir . "\" />";

?>
<!-- <meta name="omz-proxy-ssi" /> -->
	</head>
	<body>

<div id="topbar" class="bar topbar">

<?php

$tags = array("current");

if(!is_null($subpath) && !is_null($metadata) /* only fetch tags if there is a metadata file to not try and load tags from nonexistent repository */){
	$tagsRes = getTagsFromSource($subpath);
	$fetchTime += $tagsRes->timeMs;
	if($tagsRes->data !== false && $tagsRes->statusCode === 200){
		$tagsArr = json_decode($tagsRes->data, false, 8);
		if(is_array($tagsArr)){
			foreach($tagsArr as $value){
				array_push($tags, $value->name);
			}
		}
	}
}


if(metadataAvailable("icon", "string"))
	echo '<img id="logo" src="' . safeMeta($metadata->icon) . '" class="logo" />';

if(metadataAvailable("title", "string"))
	echo '<span id="title" class="title">' . safeMeta($metadata->title) . '</span>';
else if($metadataValid)
	errormsgconf("Missing title");


echo '<select id="versionSelector">';
foreach($tags as $name){
	echo '<option value="' . urlencode($name) . '" ' . ($gitTag == $name ? ' selected' : '') . '>' . htmlspecialchars($name) . '</option>';
}
echo '</select>';

?>

</div>
<div id="sidebar" class="bar sidebar">

<?php

function addSidebarEntry(string $name, string $value, int $depth, string $base){
	global $docsRootDir;
	global $contentFilename;
	global $gitTagQuery;
	if($depth > 3){
		errormsgconf("Maximum depth exceeded: 3");
		return;
	}
	$entry = new Entry($name, $value);
	if(!$entry->hasFlag("hidden")){
		if($entry->hasFlag("line")){
			echo '<div class="line"></div>';
		}else{
			echo '<a id="sidebar_entry_' . $entry->key . '" class="sidebar-entry sidebar-entry-l' . $depth . ($entry->filename == $contentFilename ? ' sidebar-entry-selected' : '')
				. ' sidebar-regularentry" href="' . $docsRootDir . $base . $entry->key . $gitTagQuery . '">' . $entry->name . '</a>';
		}
	}
}

function addSidebarObject(object $obj, int $depth, string $base){
	global $docsRootDir;
	foreach($obj as $name => $value){
		if(is_string($value)){
			addSidebarEntry($name, $value, $depth, $base);
		}else if(is_object($value)){
			$key = nameToKey($name);
			$id = 'sidebar_collapsible_' . $key;
			echo '<a id="' . $id . '" class="sidebar-entry sidebar-entry-l' . $depth . ' sidebar-collapsible" href="javascript:void(0);">' . $name . '</a><div id="' . $id . '_content">';
			addSidebarObject($value, $depth + 1, $base . $key . "/");
			echo '</div>';
		}else{
			errormsgconf("Content entry '" . $name . "' has invalid value type " . gettype($value));
			break;
		}
	}
}

if(metadataAvailable("content", "object")){
	addSidebarObject($metadata->content, 1, "/");
}else if($metadataValid){
	errormsgconf("content is not set or not an object");
}else{ // empty div to push links div to bottom
	echo '<div></div>';
}


echo '<div class="links"><a href="https://docs.omegazero.org/">omz-docs v' . $VERSION . '</a>';
foreach($links as $name => $href){
	echo ' | <a href="' . $href . '">' . $name . '</a>';
}
echo '</div>';

?>

</div>

<div id="main">
	<div id="loadingBar"></div>
	<div id="mainContent">
<?php endif; ?>


<?php

if($confinitError){
	errormsgconf($confinitError);
}else if(is_null($subpath)){
	errormsg("Invalid request path");
}else if(is_null($contentFilename)){
	errormsg("Invalid resource");
}else{
	$contentFilenameAbsolute = $contentFilename;
	if(!str_starts_with($contentFilenameAbsolute, "/")){
		$contentFilenameAbsolute = "/" . $docsBasePath . $contentFilenameAbsolute;
	}
	$contentRes = getFileFromSource($subpath, substr($contentFilenameAbsolute, 1));
	if($contentRes->data === false){
		errormsgconf("Failed to fetch content");
	}else if($contentRes->statusCode === 404){
		errormsgconf("Content file '" . $contentFilenameAbsolute . "' does not exist");
	}else if($contentRes->statusCode !== 200){
		errormsgconf("Content file request returned status " . $contentRes->statusCode);
	}else{
		$pd = null;
		if(file_exists("lib/ParsedownExtra.php")){
			require_once("lib/Parsedown.php");
			require_once("lib/ParsedownExtra.php");
			$pd = new ParsedownExtra();
		}else{
			require_once("lib/Parsedown.php");
			$pd = new Parsedown();
		}

		$mdhtml = $pd->text($contentRes->data);

		if(file_exists("lib/htmlpurifier/HTMLPurifier.standalone.php")){
			require_once("lib/htmlpurifier/HTMLPurifier.standalone.php");
			$htmlpurifierconfig = HTMLPurifier_Config::createDefault();
			if(is_null($htmlPurifierCacheDir)){
				$htmlpurifierconfig->set('Cache.DefinitionImpl', null);
			}else{
				$htmlpurifierconfig->set('Cache.SerializerPath', $htmlPurifierCacheDir);
			}
			$htmlpurifier = new HTMLPurifier($htmlpurifierconfig);
			$mdhtmlsafe = $htmlpurifier->purify($mdhtml);

			echo $mdhtmlsafe;
		}else{
			echo $mdhtml;
		}
	}
}

?>


<?php if(!$contentOnly) : ?>
	</div>
</div>

	</body>
</html>

<?php endif; ?>

<?php

header("Cache-Control: max-age=" . $cacheMaxAge);

$time = (microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000 - $fetchTime;
header("Server-Timing: docs-meta-fetch;dur=" . round($fetchTime) . ", docs-page-gen;dur=" . round($time));

header("Content-Length: " . ob_get_length());
ob_end_flush();

?>
