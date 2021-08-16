
<?php

class GitDataSource implements DataSource{

	public function __construct(array $args){
		$this->config = $args;
		if(!isset($this->config["sources"]))
			throw new Exception("Missing sources in config");
		if(!isset($this->config["docsBasePath"]) || gettype($this->config["docsBasePath"]) != "string")
			$this->config["docsBasePath"] = ".docs/";
		if(!isset($this->config["metaFileName"]) || gettype($this->config["metaFileName"]) != "string")
			$this->config["metaFileName"] = $this->config["docsBasePath"] . "meta.json";
	}


	function parseSubpath(string $path){
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
		if($sourceNum > count($this->config["sources"])){
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
		$res->source = $this->config["sources"][$sourceNum];
		return $res;
	}

	function fetchMetadata(string $versionName, object $subpath){
		$metadata = null;

		$metaRes = GitDataSource::getFileFromSource($versionName, $subpath, $this->config["metaFileName"]);
		if($metaRes->data !== false && $metaRes->statusCode === 200){
			$metadata = json_decode($metaRes->data, false, 8);
			if(is_null($metadata))
				return "Metadata is not valid JSON";
		}else{
			return "";
		}

		if(!metadataAvailable($metadata, "version", "integer"))
			$metadata->version = 1;
		if($metadata->version != 2)
			return "Unsupported metadata version: " . $metadata->version;
		else
			return $metadata;
	}

	public function getResource(string $path, string $versionName, string $rootDir) : ?object{
		$subpath = $this->parseSubpath($path);
		if(is_null($subpath))
			return null;

		$docsRootDir = $rootDir . $subpath->rootDir;

		$metadata = $this->fetchMetadata($versionName, $subpath);
		if(gettype($metadata) == "string" && $versionName != "current"){
			$metadata = $this->fetchMetadata("current", $subpath);
		}
		if(gettype($metadata) == "object"){
			if(metadataAvailable($metadata, "redirect", "string")){
				if(GitDataSource::parseRedirect($metadata->redirect, $subpath)){
					redirectTo($rootDir . "/" . GitDataSource::stringifySubpath($subpath));
					return null;
				}else{
					errormsginitconf("Redirect destination is invalid");
				}
			}else if(metadataAvailable($metadata, "delegate", "string")){
				if(GitDataSource::parseRedirect($metadata->delegate, $subpath)){
					$metadata = $this->fetchMetadata($versionName, $subpath);
				}else{
					errormsginitconf("Delegate destination is invalid");
				}
			}
		}
		if(gettype($metadata) == "string"){
			if(strlen($metadata) > 0)
				errormsginitconf($metadata);
			return null;
		}
		if(!metadataAvailable($metadata, "content", "object")){
			errormsginitconf("content is missing");
			return null;
		}

		$contentFilename = GitDataSource::getFilename($metadata->content, $subpath->path);
		if(is_null($contentFilename)){
			errormsginitconf("Invalid resource");
			return null;
		}

		$tags = array("current");
		$tagsRes = GitDataSource::getTagsFromSource($subpath);
		if($tagsRes->data !== false && $tagsRes->statusCode === 200){
			$tagsArr = json_decode($tagsRes->data, false, 8);
			if(is_array($tagsArr)){
				foreach($tagsArr as $value){
					array_push($tags, $value->name);
				}
			}
		}

		$contentFilenameAbsolute = $contentFilename;
		if(!str_starts_with($contentFilenameAbsolute, "/")){
			$contentFilenameAbsolute = "/" . $this->config["docsBasePath"] . $contentFilenameAbsolute;
		}
		$contentRes = GitDataSource::getFileFromSource($versionName, $subpath, substr($contentFilenameAbsolute, 1));
		if($contentRes->statusCode === 404){
			errormsginitconf("Content file '" . $contentFilenameAbsolute . "' does not exist");
		}else if($contentRes->statusCode !== 200){
			errormsginitconf("Content file request returned status " . $contentRes->statusCode);
		}else if($contentRes->data === false){
			errormsginitconf("Failed to fetch content");
		}

		$resource = new stdClass();
		$resource->metadata = $metadata;
		$resource->data = $contentRes->data;
		$resource->versions = $tags;
		$resource->markdown = true;
		$resource->purify = true;
		$resource->rootDir = $docsRootDir;
		$resource->sidebarContent = GitDataSource::createSidebarContent($contentFilename, $metadata->content);
		return $resource;
	}


	static function createSidebarContent(string $contentFilename, object $obj) : array{
		$content = array();
		foreach($obj as $name => $value){
			if(is_string($value)){
				$entry = new Entry($name, $value);
				if(!$entry->hasFlag("hidden")){
					$se = new stdClass();
					if($entry->hasFlag("line")){
						$se->type = "line";
					}else{
						$se->type = "link";
						$se->id = $entry->key;
						$se->name = $entry->name;
						$se->selected = $entry->filename == $contentFilename;
					}
					array_push($content, $se);
				}
			}else if(is_object($value)){
				$se = new stdClass();
				$se->type = "category";
				$se->id = GitDataSource::nameToKey($name);
				$se->name = $name;
				$se->content = GitDataSource::createSidebarContent($contentFilename, $value);
				array_push($content, $se);
			}
		}
		return $content;
	}

	static function parseRedirect(string $str, object $subpath) : bool{
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

	static function stringifySubpath(object $subpath) : string{
		if($subpath->sourceNum == 1)
			return $subpath->owner . "/" . $subpath->name . "/" . $subpath->path;
		else
			return $subpath->sourceNum . "/" . $subpath->owner . "/" . $subpath->name . "/" . $subpath->path;
	}

	static function getFileFromSource(string $gitTag, object $subpath, string $filename) : object{
		if($gitTag == "current")
			return request(strtr($subpath->source["resourceUrl"], array("$(owner)" => $subpath->owner, "$(repository)" => $subpath->name, "$(file)" => $filename)));
		else
			return request(strtr($subpath->source["taggedResourceUrl"], array("$(owner)" => $subpath->owner, "$(repository)" => $subpath->name, "$(tag)" => $gitTag, "$(file)" => $filename)));
	}

	static function getTagsFromSource(object $subpath) : object{
		return request(strtr($subpath->source["tagsUrl"], array("$(owner)" => $subpath->owner, "$(repository)" => $subpath->name)));
	}

	static function nameToKey(string $name) : string{
		return strtr($name, " ", "_");
	}

	static function getFilename(object $content, string $path) : ?string{
		static $aliasesSearched = array();
		$parts = explode("/", $path);
		$cur = $content;
		foreach($parts as $value){
			if(!is_object($cur)){
				$cur = null;
				break;
			}
			$cur_n = new stdClass();
			foreach($cur as $name => $v){
				$key = GitDataSource::nameToKey($name);
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
				return GitDataSource::getFilename($content, $alias);
			}else if($entry->hasFlag("resource"))
				return $entry->filename;
		}
		return null;
	}
}

class Entry{

	public function __construct(?string $name, string $value){
		if(!is_null($name)){
			$this->name = $name;
			$this->key = GitDataSource::nameToKey($name);
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

return "GitDataSource";

?>
