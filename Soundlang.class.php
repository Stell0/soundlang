<?php
// vim: set ai ts=4 sw=4 ft=php:
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2015 Schmooze Com Inc.
//
namespace FreePBX\modules;
class Soundlang extends \FreePBX_Helpers implements \BMO {
	private $message = '';
	private $maxTimeLimit = 250;

	public function __construct($freepbx = null) {
		$this->db = $freepbx->Database;
		$this->FreePBX = $freepbx;
	}

	public function install() {

	}
	public function uninstall() {

	}
	public function backup(){

	}
	public function restore($backup){

	}

	public function doDialplanHook(&$ext, $engine, $priority) {
	}

	public static function myDialplanHooks() {
		return 500;
	}

	/**
	 * Function used in page.soundlang.php
	 */
	public function myShowPage() {
		$action = !empty($_REQUEST['action']) ? $_REQUEST['action'] : '';

		$html .= load_view(dirname(__FILE__).'/views/main.php', array('message' => $this->message));

		$languages = array(
			'en' => _('English'),
			'es' => _('Spanish'),
			'fr' => _('French'),
			'ja' => _('Japanese'),
		);
		$language = $this->getLanguage();

		switch ($action) {
		case '':
		case 'save':
			$html .= load_view(dirname(__FILE__).'/views/select.php', array('languages' => $languages, 'language' => $language));
			break;
		case 'packages':
		case 'install':
		case 'uninstall':
			$this->getOnlinePackages();

			$packages = $this->getPackages();
			if (empty($packages)) {
				break;
			}

			usort($packages, function($a, $b) {
				/* Sort packages by type, module, language, then format. */
				if ($a['type'] == $b['type']) {
					if ($a['module'] == $b['module']) {
						if ($a['language'] == $b['language']) {
							if ($a['format'] == $b['format']) {
								return 0;
							} else {
								return ($a['format'] < $b['format']) ? -1 : 1;
							}
						} else {
							return ($a['language'] < $b['language']) ? -1 : 1;
						}
					} else {
						return ($a['module'] < $b['module']) ? -1 : 1;
					}
				} else {
					return ($a['type'] < $b['type']) ? -1 : 1;
				}
			});

			$html .= load_view(dirname(__FILE__).'/views/packages.php', array('packages' => $packages, 'languages' => $languages));
			break;
		}

		return $html;
	}

	/**
	 * Get Inital Display
	 * @param {string} $display The Page name
	 */
	public function doConfigPageInit($display) {
		$action = !empty($_REQUEST['action']) ? $_REQUEST['action'] : '';

		switch ($action) {
		case 'save':
			$language = $_REQUEST['language'];

			$this->setLanguage($language);
			break;
		case 'install':
			$package['type'] = $_REQUEST['type'];
			$package['module'] = $_REQUEST['module'];
			$package['language'] = $_REQUEST['language'];
			$package['format'] = $_REQUEST['format'];
			$package['version'] = $_REQUEST['version'];

			$this->installPackage($package);

			break;
		case 'uninstall':
			$package['type'] = $_REQUEST['type'];
			$package['module'] = $_REQUEST['module'];
			$package['language'] = $_REQUEST['language'];
			$package['format'] = $_REQUEST['format'];

			$this->uninstallPackage($package);

			break;
		}
	}

	public function getActionBar($request) {
		$action = !empty($_REQUEST['action']) ? $_REQUEST['action'] : '';

		$buttons = array();

		switch ($action) {
		case '':
		case 'save':
			$buttons['reset'] = array(
				'name' => 'reset',
				'id' => 'reset',
				'value' => _('Reset')
			);
			$buttons['submit'] = array(
				'name' => 'submit',
				'id' => 'submit',
				'value' => _('Submit')
			);
			break;
		}

		return $buttons;
	}

	public function genConfig() {
		$conf = array();

		return $conf;
	}

	public function writeConfig($conf) {
		$this->FreePBX->WriteConfig($conf);
	}

	public function getLanguage() {
		$sql = "SELECT value FROM soundlang_settings WHERE keyword = 'language';";
		$language = $this->db->getOne($sql);

		if (empty($language)) {
			$language = 'en';
		}

		return $language;
	}

	private function setLanguage($language) {
		$sql = "UPDATE soundlang_settings SET value = :language WHERE keyword = 'language';";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':language' => $language));

		needreload();
	}

	private function getPackageInstalled($package) {
		$sql = "SELECT * FROM soundlang_packs WHERE type = :type AND module = :module AND language = :language AND format = :format";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(
			':type' => $package['type'],
			':module' => $package['module'],
			':language' => $package['language'],
			':format' => $package['format'],
		));
		$installed = $sth->fetch(\PDO::FETCH_ASSOC);

		return !empty($installed) ? $installed['installed'] : NULL;
	}

	private function setPackageInstalled($package, $installed) {
		$sql = "UPDATE soundlang_packs SET installed = :installed WHERE type = :type AND module = :module AND language = :language AND format = :format";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(
			':installed' => $installed,
			':type' => $package['type'],
			':module' => $package['module'],
			':language' => $package['language'],
			':format' => $package['format'],
		));
	}

	private function getPackages() {
		$sql = "SELECT * FROM soundlang_packs";
		$sth = $this->db->prepare($sql);
		$sth->execute();

		$packages = $sth->fetchAll(\PDO::FETCH_ASSOC);
		return $packages;
	}

	private function getOnlinePackages() {
		$version = getversion();
		// we need to know the freepbx major version we have running (ie: 12.0.1 is 12.0)
		preg_match('/(\d+\.\d+)/',$version,$matches);
		$base_version = $matches[1];

		$xml = $this->getRemoteFile("/sounds-" . $base_version . ".xml");
		if(!empty($xml)) {
			$soundsobj = simplexml_load_string($xml);

			/* Convert to an associative array */
			$sounds = json_decode(json_encode($soundsobj), true);
			if (empty($sounds) || empty($sounds['sounds']) || empty($sounds['sounds']['package'])) {
				return false;
			}

			$available = $sounds['sounds']['package'];

			/* Delete packages that aren't installed */
			$sql = "DELETE FROM soundlang_packs WHERE installed IS NULL";
			$sth = $this->db->prepare($sql);
			$sth->execute();

			/* Add / Update package versions */
			$sql = "INSERT INTO soundlang_packs (type, module, language, format, version) VALUES (:type, :module, :language, :format, :version) ON DUPLICATE KEY UPDATE version = :version";
			$sth = $this->db->prepare($sql);
			foreach ($available as $package) {
				$res = $sth->execute(array(
					':type' => $package['type'],
					':module' => $package['module'],
					':language' => $package['language'],
					':format' => $package['format'],
					':version' => $package['version'],
				));
			}
			return true;
		} else {
			return false;
		}
	}

	private function installPackage($package) {
		global $amp_conf;

		$this->uninstallPackage($package);

		$tmpdir = sys_get_temp_dir();
		$pkgdir = $tmpdir . '/' . $package['type'] . '-' . $package['module'] . '-' . $package['language'] . '-' . $package['format'] . '-' . $package['version'] . '/';

		$filename = $package['type'] . '-' . $package['module'] . '-' . $package['language'] . '-' . $package['format'] . '-' . $package['version'] . '.tar.gz';

		$filedata = $this->getRemoteFile("/sounds/" . $filename);
		file_put_contents($tmpdir . "/" . $filename, $filedata);

		/* Untar into temp dir */
		@mkdir($pkgdir);
		exec("tar zxf " . $tmpdir . "/" . escapeshellarg($filename) . " -C " . escapeshellarg($pkgdir), $output, $exitcode);
		if ($exitcode != 0) {
			@rmdir($pkgdir);
			freepbx_log(FPBX_LOG_ERROR, sprintf(_("failed to open %s sounds archive."), $filename));
			return array(sprintf(_('Could not untar %s to %s'), $filename, $amp_conf['ASTVARLIBDIR'] . "/sounds/" . $package['language'] . "/"));
		}

		/* Track installed sounds */
		$olddir = getcwd();
		chdir($pkgdir);
		$glob = glob("{*.[a-z]*,*/*.[a-z]*}", GLOB_BRACE);
		$files = array_filter($glob, function($v) {
			return substr($v, -4) != ".txt";
		});
		chdir($olddir);

		if ($files && !empty($files)) {
			$sql = "INSERT INTO soundlang_prompts (type, module, language, format, filename) VALUES (:type, :module, :language, :format, :filename)";
			$sth = $this->db->prepare($sql);
			foreach ($files as $file) {
				$row = array(
					':type' => $package['type'],
					':module' => $package['module'],
					':language' => $package['language'],
					':format' => $package['format'],
					':filename' => $file,
				);
				$res = $sth->execute($row);
			}

			/* Move prompts into place */
			$destdir = $amp_conf['ASTVARLIBDIR'] . "/sounds/" . $package['language'] . "/";
			@mkdir($destdir);
			foreach ($files as $file) {
				if (!is_dir(dirname($destdir . $file))) {
					@mkdir(dirname($destdir . $file));
				}

				rename($pkgdir . $file, $destdir . $file);
			}

			$this->setPackageInstalled($package, $package['version']);

			needreload();
		}

		if (unlink($tmpdir . "/" . $filename) === false) {
			freepbx_log(FPBX_LOG_WARNING, sprintf(_("failed to delete %s from cache directory after opening sounds archive."), $filename));
		}
	}

	private function uninstallPackage($package) {
		global $amp_conf;

		$this->setPackageInstalled($package, NULL);

		$sql = "SELECT * FROM soundlang_prompts WHERE type = :type AND module = :module AND language = :language AND format = :format";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(
			':type' => $package['type'],
			':module' => $package['module'],
			':language' => $package['language'],
			':format' => $package['format'],
		));
		$files = $sth->fetchAll(\PDO::FETCH_ASSOC);

		if ($files) {
			$destdir = $amp_conf['ASTVARLIBDIR'] . "/sounds/" . $package['language'] . "/";
			foreach ($files as $file) {
				@unlink($destdir . $file['filename']);
			}

			/* Purge installed prompts */
			$sql = "DELETE FROM soundlang_prompts WHERE type = :type AND module = :module AND language = :language AND format = :format";
			$sth = $this->db->prepare($sql);
			$sth->execute(array(
				':type' => $package['type'],
				':module' => $package['module'],
				':language' => $package['language'],
				':format' => $package['format'],
			));

			needreload();
		}
	}

	private function getRemoteFile($path) {
		$modulef =& \module_functions::create();

		$contents = null;

		$mirrors = $modulef->generate_remote_urls($path, true);

		$params = $mirrors['options'];
		$params['sv'] = 2;

		foreach($mirrors['mirrors'] as $url) {
			set_time_limit($this->maxTimeLimit);

			try{
				$pest = \FreePBX::Curl()->pest($url);
				$contents = $pest->post($url . $path, $params);
				if (isset($pest->last_headers['x-regenerate-id'])) {
					$modulef->_regenerate_unique_id();
				}
				if (!empty($contents)) {
					return $contents;
				}
			} catch (\Exception $e) {
				freepbx_log(FPBX_LOG_ERROR, sprintf(_('Failed to get remote file, error was:'), (string)$e->getMessage()));
			}
		}
	}
}