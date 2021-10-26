
<?php

class FileDataSource implements DataSource{

	public function __construct(array $args){
		if(!isset($args["baseDir"]))
			throw new Exception("baseDir is not set");
		$this->baseDir = realpath($args["baseDir"]);
		if($this->baseDir === false)
			throw new Exception("baseDir does not exist");
		$this->baseDir .= "/";
	}


	public function getResource(string $path, string $versionName, string $rootDir) : ?object{
		$pathparts = explode("/", $path);
		if(count($pathparts) < 2)
			return null;
		$filename = array_pop($pathparts);
		$metaFileContent = false;
		while(count($pathparts) >= 1){
			$basePath = implode("/", $pathparts);
			$basePathAbs = realpath($this->baseDir . $basePath);
			if($basePathAbs !== false){
				$basePathAbs .= "/";
				if(!str_starts_with($basePathAbs, $this->baseDir))
					return null;
				$metaFilePath = $basePathAbs . "meta.json";
				if(file_exists($metaFilePath)){
					$metaFileContent = file_get_contents($metaFilePath);
					if($metaFileContent !== false)
						break;
				}
			}
			$filename = array_pop($pathparts) . "/" . $filename;
		}
		if($metaFileContent === false)
			return null;

		$metadata = json_decode($metaFileContent);
		if(is_null($metadata))
			return null;

		$selId = "_" . str_replace(array("/", " "), "_", $filename);
		$filePath = $basePathAbs . $filename . ".md";
		if(!str_starts_with($filePath, $this->baseDir) || !file_exists($filePath)){
			errormsginitconf("File does not exist");
			return null;
		}
		$fileData = file_get_contents($filePath);
		if($fileData === false){
			errormsginitconf("Error while reading file");
			return null;
		}

		$resource = new stdClass();
		$resource->metadata = $metadata;
		$resource->data = $fileData;
		$resource->versions = array("current");
		$resource->markdown = true;
		$resource->purify = true;
		$resource->rootDir = $rootDir . "/" . $basePath;
		$resource->sidebarContent = FileDataSource::createSidebarContent($basePathAbs, "", $selId);
		return $resource;
	}


	static function createSidebarContent(string $baseDir, string $relId, string $selId) : array{
		$content = array();
		$files = scandir($baseDir);
		if($files === false)
			return array();
		foreach($files as $f){
			if($f == "." || $f == "..")
				continue;
			$fmode = stat($baseDir . "/" . $f)["mode"];

			if(substr($f, -3) === ".md"){
				$f = substr($f, 0, strlen($f) - 3);
				$isMd = true;
			}else
				$isMd = false;

			$fid = FileDataSource::nameToKey($f);
			$absId = $relId . "_" . $fid;

			$dname = $f;
			// files and dirs may be prefixed with a number or letter followed by an underscore for sorting; those characters are skipped in display
			if(preg_match("/^[0-9a-z]_.+/", $dname))
				$dname = substr($dname, 2);
			if($dname == "-----"){
				$se = new stdClass();
				$se->type = "line";
				array_push($content, $se);
			}else if($fmode & 0100000){
				if(!$isMd) // files must end with .md
					continue;
				$se = new stdClass();
				$se->type = "link";
				$se->id = $fid;
				$se->name = $dname;
				$se->selected = $absId == $selId;
				array_push($content, $se);
			}else if($fmode & 040000){
				$se = new stdClass();
				$se->type = "category";
				$se->id = $fid;
				$se->name = $dname;
				$se->content = FileDataSource::createSidebarContent($baseDir . "/" . $f, $absId, $selId);
				array_push($content, $se);
			}
		}
		return $content;
	}

	static function nameToKey(string $name) : string{
		return strtr($name, " ", "_");
	}
}

return "FileDataSource";

?>
