<?php

$cacheMaxAge = 10;

$htmlPurifierCacheDir = null;

$links = array(
	"Home Page" => "https://example.com/"
);

$datasources = array("git");

$datasourceArgs = array(
	"git" => array(
		"sources" => array(
			1 => array(
				"resourceUrl" => "https://raw.githubusercontent.com/$(owner)/$(repository)/master/$(file)",
				"taggedResourceUrl" => "https://raw.githubusercontent.com/$(owner)/$(repository)/$(tag)/$(file)",
				"tagsUrl" => "https://api.github.com/repos/$(owner)/$(repository)/tags"
			)
		),
		"docsBasePath" => ".docs/",
		"metaFileName" => ".docs/meta.json"
	)
);

?>
