<?php
/**
 * Usage: dumplist.php [check|testmd5|generate|update|touchdir]
 *
 * From the shadows. We shall rise...
 *
 * @Author  NewEraCracker
 * @License Public Domain
 * @Version 1.1.2
 */

/** Run */
new NewEra_DumpList('./filelist.md5', array('./_incoming/', './.htaccess', './.htpasswd', './index.php'));

/** Utility static methods for dump listing */
class NewEra_DumpListUtil
{
	/** This will parse a listfile */
	public static function parse_listfile($filename)
	{
		$fileproperties = array();

		if(false === file_exists($filename))
		{
			trigger_error('Error parsing listfile: File does not exist', E_USER_WARNING);
			return false;
		}
		if(false === preg_match_all('@^; (?<mtime>[0-9]+) (?<name>[^\r\n]+)$@m', file_get_contents($filename), $comment))
		{
			trigger_error('Error parsing listfile: Unable to parse comments', E_USER_WARNING);
			return false;
		}
		if(false === preg_match_all('@^(?<md5>[0-9a-f]+) (?<name>[^\r\n]+)$@m', file_get_contents($filename), $content))
		{
			trigger_error('Error parsing listfile: Unable to parse contents', E_USER_WARNING);
			return false;
		}

		if(count($comment['name']) == count($content['name']))
		{
			for($i=0, $sz=count($comment['name']); $i<$sz; $i++)
			{
				if($comment['name'][$i] == $content['name'][$i])
				{
					/* @Hack : We have to remove the asterisk in begining and restore ./ in path for PHP to be able to work it out */
					$file = './'.substr($comment['name'][$i], 1);

					$fileproperties["{$file}"] = array(
						'mtime'=>$comment['mtime'][$i],
						'md5'  =>$content['md5'][$i]
						);
				}
				else
				{
					trigger_error('Error parsing listfile: Invalid entry order', E_USER_WARNING);
					return false;
				}
			}
		}
		else
		{
			trigger_error('Error parsing listfile: Invalid entry count', E_USER_WARNING);
			return false;
		}

		return $fileproperties;
	}

	/** This will generate a listfile */
	public static function generate_listfile($fileproperties)
	{
		// Sort file properties array
		uksort($fileproperties, 'NewEra_Compare::sort_files_by_name');

		// Init contents of list file
		$comment = $content = '';

		foreach($fileproperties as $file => $properties)
		{
			/* @Hack : We have to replace ./ in path by an asterisk for other applications (QuickSFV, TeraCopy...) to be able to work it out */
			$filename = '*'.substr($file, 2);

			$comment .= "; {$properties['mtime']} {$filename}\n";
			$content .= "{$properties['md5']} {$filename}\n";
		}

		return $comment.$content;
	}

	/** Array with the paths a dir contains */
	public static function readdir_recursive($dir='.', $show_dirs=false)
	{
		// Set types for stack and return value
		$stack = $result = array();

		// Initialize stack
		$stack[] = $dir;

		// Pop the first element of stack and evaluate it (do this until stack is fully empty)
		while($dir = array_shift($stack))
		{
			$dh = opendir($dir);
			while($dh && (false !== ($path = readdir($dh))))
			{
				if($path != '.' && $path != '..')
				{
					$path = $dir.'/'.$path;
					if(is_dir($path))
					{
						// If $show_dirs is true, add dir path to result
						if($show_dirs)
							$result[] = $path;

						// Add dir to stack for reading
						$stack[] = $path;
					}
					elseif(is_file($path))
					{
						// Add file path to result
						$result[] = $path;
					}
				}
			}
			closedir($dh);
		}

		// Sort the array using simple ordering
		sort($result);

		// Now we can return it
		return $result;
	}
}

/* Useful comparators */
class NewEra_Compare
{
	/* Ascending directory sorting by names */
	public static function sort_files_by_name($a, $b)
	{
		/* Equal */
		if($a == $b)
		{
			return 0;
		}

		/* Let strcmp decide */
		return strcmp($a, $b);
	}

	/* Ascending directory sorting by levels and names */
	public static function sort_files_by_level_asc($a, $b)
	{
		/* Equal */
		if($a == $b)
		{
			return 0;
		}

		/* Check dir levels */
		$la = substr_count($a, '/');
		$lb = substr_count($b, '/');

		/* Prioritize levels, in case of equality let sorting by names decide */
		return (($la < $lb) ? -1 : (($la == $lb) ? self::sort_files_by_name($a, $b) : 1));
	}

	/* Reverse directory sorting by levels and names */
	public static function sort_files_by_level_dsc($a, $b)
	{
		return self::sort_files_by_level_asc($b, $a);
	}
}

/** Methods used in dump listing */
class NewEra_DumpList
{
	/** Ignored paths */
	private $ignored;

	/** The file that holds the file list */
	private $listfile;

	/** Simple file list array */
	private $filelist;

	/** Detailed file list array */
	private $fileproperties;

	/** Construct the object and perform actions */
	public function __construct($listfile='./filelist.md5', $ignored=array())
	{
		$this->listfile = $listfile;

		// Build ignored paths
		$this->ignored  = array_merge(
			array(	$listfile,				 /* List file */
					'./'.basename(__FILE__), /* This file */
			), $ignored);					 /* Original ignored array */

		// Check CLI
		if(isset($_SERVER['REQUEST_METHOD']))
		{
			die('This script must be ran from CLI.');
		}

		// Check arguments count
		if($_SERVER['argc'] != 2)
		{
			die('Usage: '.basename(__FILE__)." [check|testmd5|generate|update|touchdir]\n");
		}

		// Change dir
		chdir(dirname(__FILE__));

		// Process arguments
		switch($_SERVER['argv'][1])
		{
			case 'testmd5':
				$this->dumplist_check(true);
				break;
			case 'check':
				$this->dumplist_check(false);
				break;
			case 'generate':
				$this->dumplist_generate();
				break;
			case 'update':
				$this->dumplist_update();
				break;
			case 'touchdir':
				$this->dumplist_touchdir();
				break;
			default:
				die('Usage: '.basename(__FILE__)." [check|testmd5|generate|update|touchdir]\n");
		}
	}

	/** Run the check on each file */
	private function dumplist_check($testmd5 = false)
	{
		$this->filelist = NewEra_DumpListUtil::readdir_recursive();
		$this->fileproperties = NewEra_DumpListUtil::parse_listfile($this->listfile);

		foreach($this->filelist as $file)
		{
			if(count($this->ignored))
			{
				// Check ignored files
				if(in_array($file, $this->ignored))
				{
					continue;
				}
				// Check ignored dirs
				foreach($this->ignored as $ignored)
				{
					if(is_dir($ignored) && strpos($file, $ignored) === 0)
					{
						continue 2;
					}
				}
			}

			// Handle creation case
			if(!isset($this->fileproperties["{$file}"]))
			{
				echo "{$file} is a new file.\n";
				continue;
			}
		}

		foreach($this->fileproperties as $file => $properties)
		{
			// Handle deletion
			if(!file_exists($file))
			{
				echo "{$file} does not exist.\n";
				continue;
			}

			// Handle file modification
			if(filemtime($file) != $properties['mtime'])
			{
				echo "{$file} was modified.\n";
				continue;
			}

			// Test file MD5 if required
			if($testmd5)
			{
				$md5 = md5_file($file);

				if($md5 != $properties['md5'])
				{
					echo "{$file} Expected MD5: {$properties['md5']} Got: {$md5}.\n";
					continue;
				}
			}
		}
	}

	/** Generate dump file listing */
	private function dumplist_generate()
	{
		$this->filelist = NewEra_DumpListUtil::readdir_recursive();
		$this->fileproperties = array();

		foreach($this->filelist as $file)
		{
			if(count($this->ignored))
			{
				// Check ignored files
				if(in_array($file, $this->ignored))
				{
					continue;
				}
				// Check ignored dirs
				foreach($this->ignored as $ignored)
				{
					if(is_dir($ignored) && strpos($file, $ignored) === 0)
					{
						continue 2;
					}
				}
			}

			$this->fileproperties["{$file}"] = array(
				'mtime'=> filemtime($file),
				'md5'  => md5_file($file)
				);
		}

		$contents = NewEra_DumpListUtil::generate_listfile($this->fileproperties);
		file_put_contents($this->listfile, $contents);
	}

	/** Update dump file listing */
	private function dumplist_update()
	{
		$this->filelist = NewEra_DumpListUtil::readdir_recursive();
		$this->fileproperties = NewEra_DumpListUtil::parse_listfile($this->listfile);

		foreach($this->filelist as $file)
		{
			if(count($this->ignored))
			{
				// Check ignored files
				if(in_array($file, $this->ignored))
				{
					continue;
				}
				// Check ignored dirs
				foreach($this->ignored as $ignored)
				{
					if(is_dir($ignored) && strpos($file, $ignored) === 0)
					{
						continue 2;
					}
				}
			}

			// Handle creation case
			if(!isset($this->fileproperties["{$file}"]))
			{
				$this->fileproperties["{$file}"] = array(
					'mtime'=> filemtime($file),
					'md5'  => md5_file($file)
					);
				continue;
			}
		}

		// Save the keys to remove in case there is file deletion
		$keys_to_remove = array();

		// Handle each file in the properties list
		foreach($this->fileproperties as $file => $properties)
		{
			// Handle deletion (Save it, will delete the keys later)
			if(!file_exists($file))
			{
				$keys_to_remove[] = $file;
				continue;
			}

			// Handle file modification
			if(filemtime($file) != $properties['mtime'])
			{
				$this->fileproperties["{$file}"] = array(
					'mtime'=> filemtime($file),
					'md5'  => md5_file($file)
					);
				continue;
			}
		}

		// Handle deletion (Delete the keys now)
		if(count($keys_to_remove) > 0)
		{
			foreach($keys_to_remove as $key)
			{
				unset($this->fileproperties[$key]);
			}
		}

		$contents = NewEra_DumpListUtil::generate_listfile($this->fileproperties);
		file_put_contents($this->listfile, $contents);
	}

	private function dumplist_touchdir()
	{
		// Filelist including directories
		$list = NewEra_DumpListUtil::readdir_recursive('.', true);

		// http://php.net/manual/en/function.touch.php#refsect1-function.touch-changelog
		if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && version_compare(PHP_VERSION, '5.3') < 0)
		{
			// This method will not work on Windows with PHP versions lower than 5.3
			return false;
		}

		// Easier with a bottom to top approach
		usort($list, 'NewEra_Compare::sort_files_by_level_dsc');

		// Handle list including directories. Then run
		// another pass with list without directories
		for($i = 0; $i < 2; $i++)
		{
			// Reset internal variables state
			$dir = $time = null;

			// Handle list
			foreach($list as $file)
			{
				if($i == 1 && is_dir($file))
				{
					// Ignore dir dates on pass two
					continue;
				}

				// Reset internal variables state when moving to another dir
				if($dir !== dirname($file))
				{
					$dir  = dirname($file);
					$time = 0;
				}

				// Take in account ignored paths
				if(count($this->ignored))
				{
					// Check ignored files
					if(in_array($file, $this->ignored))
					{
						continue;
					}

					// Check ignored dirs
					foreach($this->ignored as $ignored)
					{
						if(is_dir($ignored) && strpos($file, $ignored) === 0)
						{
							continue 2;
						}
					}
				}

				// Additionally blacklist certain names
				if(	false !== stripos($file, '/desktop.ini') ||
					false !== stripos($file, '/.'))
				{
					continue;
				}

				// Save current time
				$mtime = @filemtime($file);

				// Only update when mtime is correctly set and higher than time
				// Additionally check for writability to prevent errors
				if($mtime > 0 && $mtime > $time && is_writable($dir))
				{
					// Save new timestamp
					$time = $mtime;

					// Update timestamp
					touch($dir, $time);
				}
			}
		}

		// I think we should be OK
		return true;
	}
}
?>