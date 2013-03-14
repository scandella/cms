<?php
namespace Craft;

/**
 * Local source type class
 */
class LocalAssetSourceType extends BaseAssetSourceType
{

	protected $_isSourceLocal = true;

	/**
	 * Returns the name of the source type.
	 *
	 * @return string
	 */
	public function getName()
	{
		return Craft::t('Local Folder');
	}

	/**
	 * Defines the settings.
	 *
	 * @access protected
	 * @return array
	 */
	protected function defineSettings()
	{
		return array(
			'path' => array(AttributeType::String, 'required' => true),
			'url'  => array(AttributeType::String, 'required' => true, 'label' => 'URL'),
		);
	}

	/**
	 * Returns the component's settings HTML.
	 *
	 * @return string|null
	 */
	public function getSettingsHtml()
	{
		return craft()->templates->render('_components/assetsourcetypes/Local/settings', array(
			'settings' => $this->getSettings()
		));
	}

	/**
	 * Preps the settings before they're saved to the database.
	 *
	 * @param array $settings
	 * @return array
	 */
	public function prepSettings($settings)
	{
		// Add a trailing slash to the Path and URL settings
		$settings['path'] = rtrim($settings['path'], '/').'/';
		$settings['url'] = rtrim($settings['url'], '/').'/';

		return $settings;
	}

	/**
	 * Check if the FileSystem path is a writable folder
	 * @return array
	 */
	public function getSourceErrors()
	{
		$errors = array();
		if (!(IOHelper::folderExists($this->_getSourceFileSystemPath()) && IOHelper::isWritable($this->_getSourceFileSystemPath()))) {
			$errors['path'] = Craft::t("The destination folder doesn't exist or is not writable.");
		}

		return $errors;
	}


	/**
	 * Starts an indexing session.
	 *
	 * @param $sessionId
	 * @return array
	 */
	public function startIndex($sessionId)
	{
		$indexedFolderIds = array();

		$indexedFolderIds[craft()->assetIndexing->ensureTopFolder($this->model)] = true;

		$localPath = $this->_getSourceFileSystemPath();
		$fileList = IOHelper::getFolderContents($localPath, true);

		$fileList = array_filter($fileList, function ($value) use ($localPath)
		{
			$path = substr($value, strlen($localPath));
			$segments = explode('/', $path);

			foreach ($segments as $segment)
			{
				if (isset($segment[0]) && $segment[0] == '_')
				{
					return false;
				}
			}

			return true;
		});

		$offset = 0;
		$total = 0;

		foreach ($fileList as $file)
		{
			if (!preg_match(AssetsHelper::IndexSkipItemsPattern, $file))
			{
				if (is_dir($file))
				{
					$fullPath = rtrim(str_replace($this->_getSourceFileSystemPath(), '', $file), '/') . '/';
					$folderId = $this->_ensureFolderByFulPath($fullPath);
					$indexedFolderIds[$folderId] = true;
				}
				else
				{
					$indexEntry = array(
						'sourceId' => $this->model->id,
						'sessionId' => $sessionId,
						'offset' => $offset++,
						'uri' => $file,
						'size' => is_dir($file) ? 0 : filesize($file)
					);

					craft()->assetIndexing->storeIndexEntry($indexEntry);
					$total++;
				}
			}
		}

		$missingFolders = $this->_getMissingFolders($indexedFolderIds);

		return array('sourceId' => $this->model->id, 'total' => $total, 'missingFolders' => $missingFolders);
	}

	/**
	 * Get the file system path for upload source.
	 *
	 * @return string
	 */
	private function _getSourceFileSystemPath()
	{
		$path = $this->getSettings()->path;
		$path = realpath($path).'/';
		return $path;
	}

	/**
	 * Process an indexing session.
	 *
	 * @param $sessionId
	 * @param $offset
	 * @return mixed
	 */
	public function processIndex($sessionId, $offset)
	{
		$indexEntryModel = craft()->assetIndexing->getIndexEntry($this->model->id, $sessionId, $offset);

		if (empty($indexEntryModel))
		{
			return false;
		}

		// Make sure we have a trailing slash. Some people love to skip those.
		$uploadPath = $this->_getSourceFileSystemPath();

		$file = $indexEntryModel->uri;

		// This is the part of the path that actually matters
		$uriPath = substr($file, strlen($uploadPath));

		$fileModel = $this->_indexFile($uriPath);

		if ($fileModel)
		{
			craft()->assetIndexing->updateIndexEntryRecordId($indexEntryModel->id, $fileModel->id);

			$fileModel->size = $indexEntryModel->size;
			$fileModel->dateModified = IOHelper::getLastTimeModified($indexEntryModel->uri);

			if ($fileModel->kind == 'image')
			{
				list ($width, $height) = getimagesize($indexEntryModel->uri);
				$fileModel->width = $width;
				$fileModel->height = $height;
			}

			craft()->assets->storeFile($fileModel);

			return $fileModel->id;
		}

		return false;
	}

	/**
	 * Insert a file from path in folder.
	 *
	 * @param AssetFolderModel $folder
	 * @param $filePath
	 * @param $fileName
	 * @return AssetOperationResponseModel
	 * @throws Exception
	 */
	protected function _insertFileInFolder(AssetFolderModel $folder, $filePath, $fileName)
	{
		$targetFolder = $this->_getSourceFileSystemPath() . $folder->fullPath;

		// Make sure the folder is writable
		if (! IOHelper::isWritable($targetFolder))
		{
			throw new Exception(Craft::t('Target destination is not writable'));
		}

		$fileName = IOHelper::cleanFilename($fileName);

		$targetPath = $targetFolder . $fileName;
		$extension = IOHelper::getExtension($fileName);

		if (!IOHelper::isExtensionAllowed($extension))
		{
			throw new Exception(Craft::t('This file type is not allowed'));
		}

		if (IOHelper::fileExists($targetPath))
		{
			$response = new AssetOperationResponseModel();
			return $response->setPrompt($this->_getUserPromptOptions($fileName))->setDataItem('fileName', $fileName);
		}

		if (! IOHelper::copyFile($filePath, $targetPath))
		{
			throw new Exception(Craft::t('Could not copy file to target destination'));
		}

		IOHelper::changePermissions($targetPath, IOHelper::writableFilePermissions);

		$response = new AssetOperationResponseModel();
		return $response->setSuccess()->setDataItem('filePath', $targetPath);
	}

	/**
	 * Get a name replacement for a filename already taken in a folder.
	 *
	 * @param AssetFolderModel $folder
	 * @param $fileName
	 * @return string
	 */
	protected function _getNameReplacement(AssetFolderModel $folder, $fileName)
	{
		$fileList = IOHelper::getFolderContents($this->_getSourceFileSystemPath() . $folder->fullPath, false);
		$existingFiles = array();

		foreach ($fileList as $file)
		{
			$existingFiles[pathinfo($file, PATHINFO_BASENAME)] = true;
		}

		$fileParts = explode(".", $fileName);
		$extension = array_pop($fileParts);
		$fileName = join(".", $fileParts);

		for ($i = 1; $i <= 50; $i++)
		{
			if (!isset($existingFiles[$fileName.'_'.$i.'.'.$extension]))
			{
				return $fileName.'_'.$i.'.'.$extension;
			}
		}

		return false;
	}

	/**
	 * Get the timestamp of when a file transform was last modified.
	 *
	 * @param AssetFileModel $fileModel
	 * @param string $transformLocation
	 * @return mixed
	 */
	public function getTimeTransformModified(AssetFileModel $fileModel, $transformLocation)
	{
		$path = $this->_getImageServerPath($fileModel, $transformLocation);

		if (!IOHelper::fileExists($path))
		{
			return false;
		}

		return IOHelper::getLastTimeModified($path);
	}

	/**
	 * Put an image transform for the File and handle using the provided path to the source image.
	 *
	 * @param AssetFileModel $fileModel
	 * @param $handle
	 * @param $sourceImage
	 * @return mixed
	 */
	public function putImageTransform(AssetFileModel $fileModel, $handle, $sourceImage)
	{
		return IOHelper::copyFile($sourceImage, $this->_getImageServerPath($fileModel, $handle));
	}

	/**
	 * Get the image source path with the optional handle name.
	 *
	 * @param AssetFileModel $fileModel
	 * @param string $handle
	 * @return mixed
	 */
	public function getImageSourcePath(AssetFileModel $fileModel, $handle = '')
	{
		return $this->_getImageServerPath($fileModel, $handle);
	}

	/**
	 * Get the local path for an image, optionally with a size handle.
	 *
	 * @param AssetFileModel $fileModel
	 * @param string $transformLocation
	 * @return string
	 */
	private function _getImageServerPath(AssetFileModel $fileModel, $transformLocation = '')
	{
		if (!empty($transformLocation))
		{
			$transformLocation = '_'.ltrim($transformLocation, '_');
		}

		$targetFolder = $this->_getSourceFileSystemPath().$fileModel->getFolder()->fullPath;
		$targetFolder .= !empty($transformLocation) ? $transformLocation.'/': '';

		return $targetFolder.$fileModel->filename;
	}

	/**
	 * Make a local copy of the file and return the path to it.
	 *
	 * @param AssetFileModel $file
	 * @return mixed
	 */

	public function getLocalCopy(AssetFileModel $file)
	{
		$location = AssetsHelper::getTempFilePath();
		IOHelper::copyFile($this->_getFileSystemPath($file), $location);
		clearstatcache();

		return $location;
	}

	/**
	 * Get a file's system path.
	 *
	 * @param AssetFileModel $file
	 * @return string
	 */
	private function _getFileSystemPath(AssetFileModel $file)
	{
		$folder = $file->getFolder();
		return $this->_getSourceFileSystemPath().$folder->fullPath.$file->filename;
	}

	/**
	 * Delete just the source file for an Assets File.
	 *
	 * @param AssetFolderModel $folder
	 * @param $filename
	 * @return void
	 */
	protected function _deleteSourceFile(AssetFolderModel $folder, $filename)
	{
		IOHelper::deleteFile($this->_getSourceFileSystemPath().$folder->fullPath.$filename);
	}

	/**
	 * Delete all the generated image transforms for this file.
	 *
	 * @param AssetFileModel $file
	 * @return void
	 */
	protected function _deleteGeneratedImageTransforms(AssetFileModel $file)
	{
		$transformLocations = craft()->assetTransforms->getGeneratedTransformLocationsForFile($file);
		$folder = $file->getFolder();
		foreach ($transformLocations as $location)
		{
			IOHelper::deleteFile($this->_getSourceFileSystemPath().$folder->fullPath.$location.'/'.$file->filename);
		}
	}

	/**
	 * Move a file in source.
	 *
	 * @param AssetFileModel $file
	 * @param AssetFolderModel $targetFolder
	 * @param string $fileName
	 * @param string $userResponse Conflict resolution response
	 * @return mixed
	 */
	protected function _moveSourceFile(AssetFileModel $file, AssetFolderModel $targetFolder, $fileName = '', $userResponse = '')
	{
		if (empty($fileName))
		{
			$fileName = $file->filename;
		}

		$newServerPath = $this->_getSourceFileSystemPath().$targetFolder->fullPath.$fileName;

		$conflictingRecord = craft()->assets->findFile(array(
			'folderId' => $targetFolder->id,
			'filename' => $fileName
		));

		$conflict = IOHelper::fileExists($newServerPath) || (!craft()->assets->isMergeInProgress() && is_object($conflictingRecord));
		if ($conflict)
		{
			$response = new AssetOperationResponseModel();
			return $response->setPrompt($this->_getUserPromptOptions($fileName))->setDataItem('fileName', $fileName);
		}

		if (!IOHelper::move($this->_getFileSystemPath($file), $newServerPath))
		{
			$response = new AssetOperationResponseModel();
			return $response->setError(Craft::t("Could not save the file"));
		}

		if ($file->kind == 'image')
		{
			$this->_deleteGeneratedThumbnails($file);

			// Move transforms
			$transforms = craft()->assetTransforms->getGeneratedTransformLocationsForFile($file);
			$baseFromPath = $this->_getSourceFileSystemPath().$file->getFolder()->fullPath;
			$baseToPath = $this->_getSourceFileSystemPath().$targetFolder->fullPath;

			foreach ($transforms as $location)
			{
				if (IOHelper::fileExists($baseFromPath.$location.'/'.$file->filename))
				{
					IOHelper::ensureFolderExists($baseToPath.$location);
					IOHelper::move($baseFromPath.$location.'/'.$file->filename, $baseToPath.$location.'/'.$fileName);
				}
			}
		}

		$response = new AssetOperationResponseModel();
		return $response->setSuccess()
				->setDataItem('newId', $file->id)
				->setDataItem('newFileName', $fileName);
	}

	/**
	 * Return TRUE if a physical folder exists.
	 *
	 * @param AssetFolderModel $parentFolder
	 * @param $folderName
	 * @return boolean
	 */
	protected function _sourceFolderExists(AssetFolderModel $parentFolder, $folderName)
	{
		return IOHelper::folderExists($this->_getSourceFileSystemPath() . $parentFolder->fullPath . $folderName);
	}

	/**
	 * Create a physical folder, return TRUE on success.
	 *
	 * @param AssetFolderModel $parentFolder
	 * @param $folderName
	 * @return boolean
	 */
	protected function _createSourceFolder(AssetFolderModel $parentFolder, $folderName)
	{
		return IOHelper::createFolder($this->_getSourceFileSystemPath() . $parentFolder->fullPath . $folderName, IOHelper::writableFolderPermissions);
	}

	/**
	 * Rename a source folder.
	 *
	 * @param AssetFolderModel $folder
	 * @param $newName
	 * @return boolean
	 */
	protected function _renameSourceFolder(AssetFolderModel $folder, $newName)
	{
		$newFullPath = $this->_getParentFullPath($folder->fullPath).$newName.'/';

		return IOHelper::rename($this->_getSourceFileSystemPath().$folder->fullPath, $this->_getSourceFileSystemPath().$newFullPath);
	}

	/**
	 * Delete the source folder.
	 *
	 * @param AssetFolderModel $folder
	 * @return boolean
	 */
	protected function _deleteSourceFolder(AssetFolderModel $parentFolder, $folderName)
	{
		return IOHelper::deleteFolder($this->_getSourceFileSystemPath().$parentFolder->fullPath.$folderName);
	}

	/**
	 * Determines if a file can be moved internally from original source.
	 *
	 * @param BaseAssetSourceType $originalSource
	 * @return mixed
	 */
	protected function canMoveFileFrom(BaseAssetSourceType $originalSource)
	{
		return $originalSource->isSourceLocal();
	}

	/**
	 * Copy a transform for a file from source location to target location.
	 *
	 * @param AssetFileModel $file
	 * @param $source
	 * @param $target
	 * @return mixed
	 */
	public function copyTransform(AssetFileModel $file, $source, $target)
	{
		$fileFolder = $file->getFolder();
		$basePath = $this->_getSourceFileSystemPath().$fileFolder->fullPath;
		IOHelper::copyFile($basePath.$source.'/'.$file->filename, $basePath.$target.'/'.$file->filename);
	}

	/**
	 * Return true if a transform exists at the location for a file.
	 *
	 * @param AssetFileModel $file
	 * @param $location
	 * @return mixed
	 */
	public function transformExists(AssetFileModel $file, $location)
	{
		return IOHelper::fileExists($this->_getImageServerPath($file, $location));
	}

	/**
	 * Return the source's base URL.
	 *
	 * @return string
	 */
	public function getBaseUrl()
	{
		return $this->getSettings()->url;
	}


}
