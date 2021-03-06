<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2013 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\Filter\File;

use Zend\Filter\AbstractFilter;
use Zend\Filter\Exception;
use Zend\Stdlib\ErrorHandler;

class RenameUpload extends AbstractFilter
{
    /**
     * @var array
     */
    protected $options = array(
        'target'          => null,
        'use_upload_name' => false,
        'overwrite'       => false,
        'randomize'       => false,
    );

    /**
     * Store already filtered values, so we can filter multiple
     * times the same file without being block by move_uploaded_file
     * internal checks
     * 
     * @var array
     */
    protected $alreadyFiltered = array();

    /**
     * Constructor
     *
     * @param array|string $targetOrOptions The target file path or an options array
     */
    public function __construct($targetOrOptions)
    {
        if (is_array($targetOrOptions)) {
            $this->setOptions($targetOrOptions);
        } else {
            $this->setTarget($targetOrOptions);
        }
    }

    /**
     * @param  string $target Target file path or directory
     * @return RenameUpload
     */
    public function setTarget($target)
    {
        if (!is_string($target)) {
            throw new Exception\InvalidArgumentException(
                'Invalid target, must be a string'
            );
        }
        $this->options['target'] = $target;
        return $this;
    }

    /**
     * @return string Target file path or directory
     */
    public function getTarget()
    {
        return $this->options['target'];
    }

    /**
     * @param  boolean $flag When true, this filter will use the $_FILES['name']
     *                       as the target filename.
     *                       Otherwise, it uses the default 'target' rules.
     * @return RenameUpload
     */
    public function setUseUploadName($flag = true)
    {
        $this->options['use_upload_name'] = (boolean) $flag;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getUseUploadName()
    {
        return $this->options['use_upload_name'];
    }

    /**
     * @param  boolean $flag Shall existing files be overwritten?
     * @return RenameUpload
     */
    public function setOverwrite($flag = true)
    {
        $this->options['overwrite'] = (boolean) $flag;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getOverwrite()
    {
        return $this->options['overwrite'];
    }

    /**
     * @param  boolean $flag Shall target files have a random postfix attached?
     * @return RenameUpload
     */
    public function setRandomize($flag = true)
    {
        $this->options['randomize'] = (boolean) $flag;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getRandomize()
    {
        return $this->options['randomize'];
    }

    /**
     * Defined by Zend\Filter\Filter
     *
     * Renames the file $value to the new name set before
     * Returns the file $value, removing all but digit characters
     *
     * @param  string|array $value Full path of file to change or $_FILES data array
     * @throws Exception\RuntimeException
     * @return string|array The new filename which has been set, or false when there were errors
     */
    public function filter($value)
    {
        // An uploaded file? Retrieve the 'tmp_name'
        $isFileUpload = (is_array($value) && isset($value['tmp_name']));
        if ($isFileUpload) {
            $uploadData = $value;
            $sourceFile = $value['tmp_name'];
        } else {
            $uploadData = array(
                'tmp_name' => $value,
                'name'     => $value,
            );
            $sourceFile = $value;
        }

        if (isset($this->alreadyFiltered[$sourceFile])) {
            return $this->alreadyFiltered[$sourceFile];
        }

        $targetFile = $this->getFinalTarget($uploadData);
        if (!file_exists($sourceFile) || $sourceFile == $targetFile) {
            return $value;
        }

        $this->checkFileExists($targetFile);
        $this->moveUploadedFile($sourceFile, $targetFile);

        $return = $targetFile;
        if ($isFileUpload) {
            $return = $uploadData;
            $return['tmp_name'] = $targetFile;
        }

        $this->alreadyFiltered[$sourceFile] = $return;

        return $return;
    }

    /**
     * @param  string $sourceFile Source file path
     * @param  string $targetFile Target file path
     * @throws \Zend\Filter\Exception\RuntimeException
     * @return boolean
     */
    protected function moveUploadedFile($sourceFile, $targetFile)
    {
        ErrorHandler::start();
        $result = move_uploaded_file($sourceFile, $targetFile);
        $warningException = ErrorHandler::stop();
        if (!$result || null !== $warningException) {
            throw new Exception\RuntimeException(
                sprintf("File '%s' could not be renamed. An error occurred while processing the file.", $sourceFile),
                0, $warningException
            );
        }

        return $result;
    }

    /**
     * @param  string $targetFile Target file path
     * @throws \Zend\Filter\Exception\InvalidArgumentException
     */
    protected function checkFileExists($targetFile)
    {
        if (file_exists($targetFile)) {
            if ($this->getOverwrite()) {
                unlink($targetFile);
            } else {
                throw new Exception\InvalidArgumentException(
                    sprintf("File '%s' could not be renamed. It already exists.", $targetFile)
                );
            }
        }
    }

    /**
     * @param  array $uploadData $_FILES array
     * @return string
     */
    protected function getFinalTarget($uploadData)
    {
        $source = $uploadData['tmp_name'];
        $target = $this->getTarget();
        if (!isset($target) || $target == '*') {
            $target = $source;
        }

        // Get the target directory
        if (is_dir($target)) {
            $targetDir = $target;
            $last      = $target[strlen($target) - 1];
            if (($last != '/') && ($last != '\\')) {
                $targetDir .= DIRECTORY_SEPARATOR;
            }
        } else {
            $info      = pathinfo($target);
            $targetDir = $info['dirname'] . DIRECTORY_SEPARATOR;
        }

        // Get the target filename
        if ($this->getUseUploadName()) {
            $targetFile = basename($uploadData['name']);
        } elseif (!is_dir($target)) {
            $targetFile = basename($target);
        } else {
            $targetFile = basename($source);
        }

        if ($this->getRandomize()) {
            $targetFile = $this->applyRandomToFilename($targetFile);
        }

        return $targetDir . $targetFile;
    }

    /**
     * @param  string $filename
     * @return string
     */
    protected function applyRandomToFilename($filename)
    {
        $info = pathinfo($filename);
        $filename = $info['filename'] . uniqid('_');
        if (isset($info['extension'])) {
            $filename .= '.' . $info['extension'];
        }
        return $filename;
    }
}
