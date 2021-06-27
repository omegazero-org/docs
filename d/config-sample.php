<?php

$sources = array(
	1 => array(
		"resourceUrl" => "https://raw.githubusercontent.com/$(owner)/$(repository)/master/$(file)",
		"taggedResourceUrl" => "https://raw.githubusercontent.com/$(owner)/$(repository)/$(tag)/$(file)",
		"tagsUrl" => "https://api.github.com/repos/$(owner)/$(repository)/tags"
	)
);

$docsBasePath = ".docs/";
$metaFileName = $docsBasePath . "meta.json";

$cacheMaxAge = 10;

$htmlPurifierCacheDir = null;

$links = array(
	"Home Page" => "https://example.com/"
);

?>
