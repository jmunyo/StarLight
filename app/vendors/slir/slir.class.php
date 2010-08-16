<?php
/**
 * Class definition file for SLIR (Smart Lencioni Image Resizer)
 *
 * This file is part of SLIR (Smart Lencioni Image Resizer).
 *
 * SLIR is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * SLIR is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with SLIR.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @copyright Copyright � 2010, Joe Lencioni
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public
 * License version 3 (GPLv3)
 * @since 2.0
 * @package SLIR
 */
 
/* $Id: slir.class.php 92 2010-02-26 05:01:47Z joe.lencioni $ */

/**
 * SLIR (Smart Lencioni Image Resizer)
 * Resizes images, intelligently sharpens, crops based on width:height ratios,
 * color fills transparent GIFs and PNGs, and caches variations for optimal
 * performance.
 *
 * I love to hear when my work is being used, so if you decide to use this,
 * feel encouraged to send me an email. I would appreciate it if you would
 * include a link on your site back to Shifting Pixel (either the SLIR page or
 * shiftingpixel.com), but don�t worry about including a big link on each page
 * if you don�t want to�one will do just nicely. Feel free to contact me to
 * discuss any specifics (joe@shiftingpixel.com).
 *
 * REQUIREMENTS:
 *     - PHP 5.1.0+
 *     - GD
 *
 * RECOMMENDED:
 *     - mod_rewrite
 *
 * USAGE:
 * To use, place an img tag with the src pointing to the path of SLIR (typically
 * "/slir/") followed by the parameters, followed by the path to the source
 * image to resize. All parameters follow the pattern of a one-letter code and
 * then the parameter value:
 *     - Maximum width = w
 *     - Maximum height = h
 *     - Crop ratio = c
 *     - Quality = q
 *     - Background fill color = b
 *     - Progressive = p
 *
 * Note: filenames that include special characters must be URL-encoded (e.g.
 * plus sign, +, should be encoded as %2B) in order for SLIR to recognize them
 * properly. This can be accomplished by passing your filenames through PHP's
 * rawurlencode() or urlencode() function.
 * 
 * EXAMPLES:
 *
 * Resizing a JPEG to a max width of 100 pixels and a max height of 100 pixels:
 * <code><img src="/slir/w100-h100/path/to/image.jpg" alt="Don't forget your alt
 * text" /></code>
 *
 * Resizing and cropping a JPEG into a square:
 * <code><img src="/slir/w100-h100-c1:1/path/to/image.jpg" alt="Don't forget
 * your alt text" /></code>
 *
 * Resizing a JPEG without interlacing (for use in Flash):
 * <code><img src="/slir/w100-p0/path/to/image.jpg" alt="Don't forget your alt
 * text" /></code>
 *
 * Matting a PNG with #990000:
 * <code><img src="/slir/b900/path/to/image.png" alt="Don't forget your alt
 * text" /></code>
 *
 * Without mod_rewrite (not recommended)
 * <code><img src="/slir/?w=100&amp;h=100&amp;c=1:1&amp;i=/path/to/image.jpg"
 * alt="Don't forget your alt text" /></code>
 *
 * @author Joe Lencioni <joe@shiftingpixel.com>
 * @date $Date: 2010-02-25 23:01:47 -0600 (Thu, 25 Feb 2010) $
 * @version $Revision: 92 $
 * @package SLIR
 *
 * @uses PEL
 * 
 * @todo lock files when writing?
 * @todo Prevent SLIR from calling itself
 * @todo Percentage resizing?
 * @todo Animated GIF resizing?
 * @todo Seam carving?
 * @todo Crop zoom?
 * @todo Crop offsets?
 * @todo Periodic cache clearing?
 * @todo Remote image fetching?
 * @todo Alternative support for ImageMagick?
 * @todo Prevent files in cache from being read directly?
 * @todo split directory initialization and variable checking into a separate
 * install/upgrade script with friendly error messages, an opportunity to give a
 * tip, and a button that tells me they are using it on their site if they like
 * @todo document new code
 * @todo clean up new code
 */
class SLIR
{
	/**
	 * @since 2.0
	 * @var string
	 */
	const VERSION	= '2.0b3';
	
	/**
	 * @since 2.0
	 * @var integer
	 */
	const CROP_MODE_CENTERED	= 1;
	
	/**
	 * @since 2.0
	 * @var integer
	 */
	const CROP_MODE_SMART		= 2;

	/**
	 * Request object
	 *
	 * @since 2.0
	 * @uses SLIRRequest
	 * @var object
	 */
	private $request;

	/**
	 * Source image object
	 *
	 * @since 2.0
	 * @uses SLIRImage
	 * @var object
	 */
	private $source;

	/**
	 * Rendered image object
	 *
	 * @since 2.0
	 * @uses SLIRImage
	 * @var object
	 */
	private $rendered;

	/**
	 * Whether or not the cache has already been initialized
	 *
	 * @since 2.0
	 * @var boolean
	 */
	private $isCacheInitialized	= FALSE;

	/**
	 * The magic starts here
	 *
	 * @since 2.0
	 */
	final public function __construct()
	{
		// This helps prevents unnecessary warnings (which messes up images)
		// on servers that are set to display E_STRICT errors.
		error_reporting(error_reporting() & ~E_STRICT);
		
		$this->getConfig();
		
		$this->request	= new SLIRRequest();
		
		// Check the cache based on the request URI
		if (SLIR_USE_REQUEST_CACHE && $this->isRequestCached())
			$this->serveRequestCachedImage();
			
		// Set up our error handler after the request cache to help keep
		// everything humming along nicely
		require 'slirexception.class.php';
		set_error_handler(array('SLIRException', 'error'));
		
		// Set all parameters for resizing
		$this->setParameters();

		// See if there is anything we actually need to do
		if ($this->isSourceImageDesired())
			$this->serveSourceImage();
			
		// Determine rendered dimensions
		$this->setRenderedProperties();

		// Check the cache based on the properties of the rendered image
		if (!$this->isRenderedCached() || !$this->serveRenderedCachedImage())
		{
			// Image is not cached in any way, so we need to render the image,
			// cache it, and serve it up to the client
			$this->render();
			$this->serveRenderedImage();
		} // if
	}

	/**
	 * Includes the configuration file
	 *
	 * @since 2.0
	 */
	final private function getConfig()
	{
		if (file_exists(self::configFilename()))
		{
			require self::configFilename();
		}
		else if (file_exists('slir-config-sample.php'))
		{
			if (copy('slir-config-sample.php', self::configFilename()))
				require self::configFilename();
			else
				throw new SLIRException('Could not load configuration file. '
					. 'Please copy "slir-config-sample.php" to '
					. '"' . self::configFilename() . '".');
		}
		else
		{
			throw new SLIRException('Could not find "' . self::configFilename() . '" or '
				. '"slir-config-sample.php"');
		} // if
	}
	
	/**
	 * Returns the configuration filename. Allows the developer to specify an alternate configuration file.
	 *
	 * @since 2.0
	 */
	final private function configFilename()
	{
		if (defined('SLIR_CONFIG_FILENAME'))
			return SLIR_CONFIG_FILENAME;
		else
			return 'slir-config.php';
	}
	
	/**
	 * Sets up parameters for image resizing
	 *
	 * @since 2.0
	 */
	final private function setParameters()
	{
		$this->source		= new SLIRImage();
		$this->source->path	= $this->request->path;
		
		// If either a max width or max height are not specified or larger than
		// the source image we default to the dimension of the source image so
		// they do not become constraints on our resized image.
		if (!$this->request->width || $this->request->width > $this->source->width)
			$this->request->width	= $this->source->width;

		if (!$this->request->height ||  $this->request->height > $this->source->height)
			$this->request->height	= $this->source->height;
	}

	/**
	 * Renders specified changes to the image
	 *
	 * @since 2.0
	 */
	final private function render()
	{
		// We don't want to run out of memory
		ini_set('memory_limit', SLIR_MEMORY_TO_ALLOCATE);

		$this->source->createImageFromFile();
		$this->rendered->createBlankImage();
		$this->rendered->background($this->isBackgroundFillOn());

		$this->copySourceToRendered();
		$this->source->destroyImage();
		
		$this->rendered->crop($this->isBackgroundFillOn());
		$this->rendered->sharpen($this->calculateSharpnessFactor());
		$this->rendered->interlace();
	}
	
	/**
	 * @since 2.0
	 */
	final private function copySourceToRendered()
	{
		// Resample the original image into the resized canvas we set up earlier
		if ($this->source->width != $this->rendered->width || $this->source->height != $this->rendered->height)
		{
			ImageCopyResampled(
				$this->rendered->image,
				$this->source->image,
				0,
				0,
				0,
				0,
				$this->rendered->width,
				$this->rendered->height,
				$this->source->width,
				$this->source->height
			);
		}
		else // No resizing is needed, so make a clean copy
		{
			ImageCopy(
				$this->rendered->image,
				$this->source->image,
				0,
				0,
				0,
				0,
				$this->source->width,
				$this->source->height
			);
		} // if
	}
	
	/**
	 * @return integer
	 * @since 2.0
	 */
	final private function calculateSharpnessFactor()
	{
		return $this->calculateASharpnessFactor($this->source->area(), $this->rendered->area());
	}
	
	/**
	 * Calculates sharpness factor to be used to sharpen an image based on the
	 * area of the source image and the area of the destination image
	 *
	 * @since 2.0
	 * @author Ryan Rud
	 * @link http://adryrun.com
	 *
	 * @param integer $sourceArea Area of source image
	 * @param integer $destinationArea Area of destination image
	 * @return integer Sharpness factor
	 */
	final private function calculateASharpnessFactor($sourceArea, $destinationArea)
	{
		$final	= sqrt($destinationArea) * (750.0 / sqrt($sourceArea));
		$a		= 52;
		$b		= -0.27810650887573124;
		$c		= .00047337278106508946;

		$result = $a + $b * $final + $c * $final * $final;

		return max(round($result), 0);
	}

	/**
	 * @since 2.0
	 * @param string $cacheFilePath
	 * @return boolean
	 */
	final private function copyIPTC($cacheFilePath)
	{
		$data	= '';

		$iptc	= $this->source->iptc;

		// Originating program
		$iptc['2#065']	= array('Smart Lencioni Image Resizer');

		// Program version
		$iptc['2#070']	= array(SLIR::VERSION);

		foreach($iptc as $tag => $iptcData)
		{
			$tag	= substr($tag, 2);
			$data	.= $this->makeIPTCTag(2, $tag, $iptcData[0]);
		}

		// Embed the IPTC data
		return iptcembed($data, $cacheFilePath);
	}

	/**
	 * @since 2.0
	 * @author Thies C. Arntzen
	 */
	final function makeIPTCTag($rec, $data, $value)
	{
		$length = strlen($value);
		$retval = chr(0x1C) . chr($rec) . chr($data);

		if($length < 0x8000)
		{
			$retval .= chr($length >> 8) .  chr($length & 0xFF);
		}
		else
		{
			$retval .= chr(0x80) .
					   chr(0x04) .
					   chr(($length >> 24) & 0xFF) .
					   chr(($length >> 16) & 0xFF) .
					   chr(($length >> 8) & 0xFF) .
					   chr($length & 0xFF);
		}

		return $retval . $value;
	}

	/**
	 * Checks parameters against the image's attributes and determines whether
	 * anything needs to be changed or if we simply need to serve up the source
	 * image
	 *
	 * @since 2.0
	 * @return boolean
	 * @todo Add check for JPEGs and progressiveness
	 */
	final private function isSourceImageDesired()
	{
		if ($this->isWidthDifferent()
			|| $this->isHeightDifferent()
			|| $this->isBackgroundFillOn()
			|| $this->isQualityOn()
			|| $this->isCroppingNeeded()
			)
			return FALSE;
		else
			return TRUE;

	}

	/**
	 * @since 2.0
	 * @return boolean
	 */
	final private function isWidthDifferent()
	{
		if ($this->request->width !== NULL
			&& $this->request->width < $this->source->width
			)
			return TRUE;
		else
			return FALSE;
	}

	/**
	 * @since 2.0
	 * @return boolean
	 */
	final private function isHeightDifferent()
	{
		if ($this->request->height !== NULL
			&& $this->request->height < $this->source->height
			)
			return TRUE;
		else
			return FALSE;
	}

	/**
	 * @since 2.0
	 * @return boolean
	 */
	final private function isBackgroundFillOn()
	{
		if ($this->request->isBackground() && $this->source->isAbleToHaveTransparency())
			return TRUE;
		else
			return FALSE;
	}

	/**
	 * @since 2.0
	 * @return boolean
	 */
	final private function isQualityOn()
	{
		return $this->request->isQuality();
	}

	/**
	 * @since 2.0
	 * @return boolean
	 */
	final private function isCroppingNeeded()
	{
		if ($this->request->isCropping() && $this->request->cropRatio['ratio'] != $this->source->ratio())
			return TRUE;
		else
			return FALSE;
	}

	/**
	 * Computes and sets properties of the rendered image, such as the actual
	 * width, height, and quality
	 *
	 * @since 2.0
	 */
	final private function setRenderedProperties()
	{
		$this->rendered	= new SLIRImage();
		
		// Set default properties of the rendered image
		$this->rendered->width	= $this->source->width;
		$this->rendered->height	= $this->source->height;
		
		// Cropping
		/*
		To determine the width and height of the rendered image, the following
		should occur.
		
		If cropping an image is required, we need to:
		 1. Compute the dimensions of the source image after cropping before
			resizing.
		 2. Compute the dimensions of the resized image before cropping. One of 
			these dimensions may be greater than maxWidth or maxHeight because
			they are based on the dimensions of the final rendered image, which
			will be cropped to fit within the specified maximum dimensions.
		 3. Compute the dimensions of the resized image after cropping. These
			must both be less than or equal to maxWidth and maxHeight.
		 4. Then when rendering, the image needs to be resized, crop offsets
			need to be computed based on the desired method (smart or centered),
			and the image needs to be cropped to the specified dimensions.
		
		If cropping an image is not required, we need to compute the dimensions
		of the image without cropping. These must both be less than or equal to
		maxWidth and maxHeight.
		*/
		if ($this->isCroppingNeeded())
		{
			// Determine the dimensions of the source image after cropping and
			// before resizing
			
			if ($this->request->cropRatio['ratio'] > $this->source->ratio())
			{
				// Image is too tall so we will crop the top and bottom
				$this->source->cropHeight	= $this->source->width / $this->request->cropRatio['ratio'];
				$this->source->cropWidth	= $this->source->width;
			}
			else
			{
				// Image is too wide so we will crop off the left and right sides
				$this->source->cropWidth	= $this->source->height * $this->request->cropRatio['ratio'];
				$this->source->cropHeight	= $this->source->height;
			} // if
		} // if

		if (floor($this->resizeWidthFactor() * $this->source->height) <= $this->request->height)
		{
			// Resize the image based on width
			$this->rendered->height	= ceil($this->resizeWidthFactor() * $this->source->height);
			$this->rendered->width	= ceil($this->resizeWidthFactor() * $this->source->width);
			
			// Determine dimensions after cropping
			if ($this->isCroppingNeeded())
			{
				$this->rendered->cropHeight	= ceil($this->resizeWidthFactor() * $this->source->cropHeight);
				$this->rendered->cropWidth	= ceil($this->resizeWidthFactor() * $this->source->cropWidth);
			} // if
		}
		else if (floor($this->resizeHeightFactor() * $this->source->width) <= $this->request->width)
		{
			// Resize the image based on height
			$this->rendered->width	= ceil($this->resizeHeightFactor() * $this->source->width);
			$this->rendered->height	= ceil($this->resizeHeightFactor() * $this->source->height);
			
			// Determine dimensions after cropping
			if ($this->isCroppingNeeded())
			{
				$this->rendered->cropHeight	= ceil($this->resizeHeightFactor() * $this->source->cropHeight);
				$this->rendered->cropWidth	= ceil($this->resizeHeightFactor() * $this->source->cropWidth);
			} // if
		}
		else if ($this->isCroppingNeeded()) // No resizing is needed but we still need to crop
		{
			$ratio	= ($this->resizeUncroppedWidthFactor() > $this->resizeUncroppedHeightFactor())
				? $this->resizeUncroppedWidthFactor() : $this->resizeUncroppedHeightFactor();
				
			$this->rendered->width		= ceil($ratio * $this->source->width);
			$this->rendered->height		= ceil($ratio * $this->source->height);
			
			$this->rendered->cropWidth	= ceil($ratio * $this->source->cropWidth);
			$this->rendered->cropHeight	= ceil($ratio * $this->source->cropHeight);
		} // if

		// Determine the quality of the output image
		$this->rendered->quality		= ($this->request->quality !== NULL)
			? $this->request->quality : SLIR_DEFAULT_QUALITY;

		// Set up the appropriate image handling parameters based on the original
		// image's mime type
		// @todo some of this code should be moved to the SLIRImage class
		$this->rendered->mime				= $this->source->mime;
		if ($this->source->isGIF())
		{
			// We need to convert GIFs to PNGs
			$this->rendered->mime			= 'image/png';
			$this->rendered->progressive	= FALSE;

			// We are converting the GIF to a PNG, and PNG needs a
			// compression level of 0 (no compression) through 9
			$this->rendered->quality		= round(10 - ($this->rendered->quality / 10));
		}
		else if ($this->source->isPNG())
		{
			$this->rendered->progressive	= FALSE;

			// PNG needs a compression level of 0 (no compression) through 9
			$this->rendered->quality		= round(10 - ($this->rendered->quality / 10));
		}
		else if ($this->source->isJPEG())
		{
				$this->rendered->progressive	= ($this->request->progressive !== NULL)
					? $this->request->progressive : SLIR_DEFAULT_PROGRESSIVE_JPEG;
				$this->rendered->background		= NULL;
		}
		else
		{
			throw new SLIRException("Unable to determine type of source image");
		} // if
		
		if ($this->isBackgroundFillOn())
			$this->rendered->background	= $this->request->background;
	}
	
	/**
	 * @since 2.0
	 * @return float
	 */
	final private function resizeWidthFactor()
	{
		if ($this->source->cropWidth !== NULL)
			return $this->resizeCroppedWidthFactor();
		else
			return $this->resizeUncroppedWidthFactor();
	}
	
	/**
	 * @since 2.0
	 * @return float
	 */
	final private function resizeUncroppedWidthFactor()
	{
		return $this->request->width / $this->source->width;
	}
	
	/**
	 * @since 2.0
	 * @return float
	 */
	final private function resizeCroppedWidthFactor()
	{
		return $this->request->width / $this->source->cropWidth;
	}
	
	/**
	 * @since 2.0
	 * @return float
	 */
	final private function resizeHeightFactor()
	{
		if ($this->source->cropHeight !== NULL)
			return $this->resizeCroppedHeightFactor();
		else
			return $this->resizeUncroppedHeightFactor();
	}
	
	/**
	 * @since 2.0
	 * @return float
	 */
	final private function resizeUncroppedHeightFactor()
	{
		return $this->request->height / $this->source->height;
	}
	
	/**
	 * @since 2.0
	 * @return float
	 */
	final private function resizeCroppedHeightFactor()
	{
		return $this->request->height / $this->source->cropHeight;
	}
	
	/**
	 * @since 2.0
	 * @return boolean
	 */
	final private function isRenderedCached()
	{
		return $this->isCached($this->renderedCacheFilePath());
	}

	/**
	 * @since 2.0
	 * @return boolean
	 */
	final private function isRequestCached()
	{
		return $this->isCached($this->requestCacheFilePath());
	}

	/**
	 * @since 2.0
	 * @param string $cacheFilePath
	 * @return boolean
	 */
	final private function isCached($cacheFilePath)
	{
		if (!file_exists($cacheFilePath))
			return FALSE;

		$cacheModified	= filemtime($cacheFilePath);

		if (!$cacheModified)
			return FALSE;

		$imageModified	= filectime($this->request->fullPath());

		if ($imageModified >= $cacheModified)
			return FALSE;
		else
			return TRUE;
	}

	/**
	 * @since 2.0
	 * @return string
	 */
	final private function renderedCacheFilePath()
	{
		return SLIR_CACHE_DIR . '/rendered' . $this->renderedCacheFilename();
	}
	
	/**
	 * @since 2.0
	 * @return string
	 */
	private function renderedCacheFilename()
	{
		return '/' . md5($this->request->fullPath() . serialize($this->rendered->cacheParameters()));
	}

	/**
	 * @since 2.0
	 * @return string
	 */
	final private function requestCacheFilename()
	{
		return '/' . md5($_SERVER['HTTP_HOST'] . '/' . $this->requestURI());
	}
	
	/**
	 * @since 2.0
	 * @return string
	 */
	final private function requestURI()
	{
		if (defined('SLIR_FORCE_QUERY_STRING') && SLIR_FORCE_QUERY_STRING)
			return $_SERVER['SCRIPT_NAME'] . '?' . http_build_query($_GET);
		else
			return $_SERVER['REQUEST_URI'];
	}

	/**
	 * @since 2.0
	 * @return string
	 */
	final private function requestCacheFilePath()
	{
		return SLIR_CACHE_DIR . '/request' . $this->requestCacheFilename();
	}

	/**
	 * Write an image to the cache
	 *
	 * @since 2.0
	 * @param string $imageData
	 * @return boolean
	 */
	final private function cache()
	{
		$this->cacheRendered();
		
		if (SLIR_USE_REQUEST_CACHE)
			return $this->cacheRequest($this->rendered->data, TRUE);
		else
			return TRUE;
	}

	/**
	 * Write an image to the cache based on the properties of the rendered image
	 *
	 * @since 2.0
	 * @return boolean
	 */
	final private function cacheRendered()
	{
		$this->rendered->data	= $this->cacheFile(
			$this->renderedCacheFilePath(),
			$this->rendered->data,
			TRUE
		);
		
		return TRUE;
	}

	/**
	 * Write an image to the cache based on the request URI
	 *
	 * @since 2.0
	 * @param string $imageData
	 * @param boolean $copyEXIF
	 * @return string
	 */
	final private function cacheRequest($imageData, $copyEXIF = TRUE)
	{
		return $this->cacheFile(
			$this->requestCacheFilePath(),
			$imageData,
			$copyEXIF,
			$this->renderedCacheFilePath()
		);
	}

	/**
	 * Write an image to the cache based on the properties of the rendered image
	 *
	 * @since 2.0
	 * @param string $cacheFilePath
	 * @param string $imageData
	 * @param boolean $copyEXIF
	 * @param string $symlinkToPath
	 * @return string|boolean
	 */
	final private function cacheFile($cacheFilePath, $imageData, $copyEXIF = TRUE, $symlinkToPath = NULL)
	{
		$this->initializeCache();
		
		// Try to create just a symlink to minimize disk space
		if ($symlinkToPath && @symlink($symlinkToPath, $cacheFilePath))
			return TRUE;

		// Create the file
		if (!file_put_contents($cacheFilePath, $imageData))
			return FALSE;

		if (SLIR_COPY_EXIF && $copyEXIF && $this->source->isJPEG())
		{
			// Copy IPTC data
			if (isset($this->source->iptc) && !$this->copyIPTC($cacheFilePath))
				return FALSE;

			// Copy EXIF data
			$imageData	= $this->copyEXIF($cacheFilePath);
		} // if

		return $imageData;
	}

	/**
	 * Copy the source image's EXIF information to the new file in the cache
	 *
	 * @since 2.0
	 * @uses PEL
	 * @param string $cacheFilePath
	 * @return mixed string contents of image on success, FALSE on failure
	 */
	final private function copyEXIF($cacheFilePath)
	{
		// Make sure to suppress strict warning thrown by PEL
		@require_once dirname(__FILE__) . '/pel-0.9.1/PelJpeg.php';
		
		$jpeg		= new PelJpeg($this->source->fullPath());
		$exif		= $jpeg->getExif();
		
		if ($exif)
		{
			$jpeg		= new PelJpeg($cacheFilePath);
			$jpeg->setExif($exif);
			$imageData	= $jpeg->getBytes();
			if (!file_put_contents($cacheFilePath, $imageData))
				return FALSE;
			
			return $imageData;
		} // if
		
		return file_get_contents($cacheFilePath);
	}

	/**
	 * Makes sure the cache directory exists, is readable, and is writable
	 *
	 * @since 2.0
	 * @return boolean
	 */
	final private function initializeCache()
	{
		if ($this->isCacheInitialized)
			return TRUE;

		$this->initializeDirectory(SLIR_CACHE_DIR);
		$this->initializeDirectory(SLIR_CACHE_DIR . '/rendered', FALSE);
		$this->initializeDirectory(SLIR_CACHE_DIR . '/request', FALSE);

		$this->isCacheInitialized	= TRUE;
		return TRUE;
	}

	/**
	 * @since 2.0
	 * @param string $path Directory to initialize
	 * @param boolean $verifyReadWriteability
	 * @return boolean
	 */
	final private function initializeDirectory($path, $verifyReadWriteability = TRUE, $test = FALSE)
	{
		if (!file_exists($path))
		{
			if (!@mkdir($path, 0755, TRUE))
			{
				header('HTTP/1.1 500 Internal Server Error');
				throw new SLIRException("Directory ($path) does not exist and was unable to be created. Please create the directory.");
			}
		}

		if (!$verifyReadWriteability)
			return TRUE;

		// Make sure we can read and write the cache directory
		if (!is_readable($path))
		{
			header('HTTP/1.1 500 Internal Server Error');
			throw new SLIRException("Directory ($path) is not readable");
		}
		else if (!is_writable($path))
		{
			header('HTTP/1.1 500 Internal Server Error');
			throw new SLIRException("Directory ($path) is not writable");
		}

		return TRUE;
	}

	/**
	 * Serves the unmodified source image
	 *
	 * @since 2.0
	 */
	final private function serveSourceImage()
	{
		$this->serveFile(
			$this->source->fullPath(),
			NULL,
			NULL,
			NULL,
			$this->source->mime,
			'source'
		);
		
		exit();
	}

	/**
	 * Serves the image from the cache based on the properties of the rendered
	 * image
	 *
	 * @since 2.0
	 */
	final private function serveRenderedCachedImage()
	{
		return $this->serveCachedImage($this->renderedCacheFilePath(), 'rendered');
	}

	/**
	 * Serves the image from the cache based on the request URI
	 *
	 * @since 2.0
	 */
	final private function serveRequestCachedImage()
	{
		return $this->serveCachedImage($this->requestCacheFilePath(), 'request');
	}

	/**
	 * Serves the image from the cache
	 *
	 * @since 2.0
	 * @param string $cacheFilePath
	 * @param string $cacheType Can be 'request' or 'image'
	 */
	final private function serveCachedImage($cacheFilePath, $cacheType)
	{
		// Serve the image
		$data = $this->serveFile(
			$cacheFilePath,
			NULL,
			NULL,
			NULL,
			NULL,
			"$cacheType cache"
		);
		
		// If we are serving from the rendered cache, create a symlink in the
		// request cache to the rendered file
		if ($cacheType != 'request')
			$this->cacheRequest($data, FALSE);
		
		exit();
	}
	
	/**
	 * Determines the mime type of an image
	 * 
	 * @since 2.0
	 * @param string $path
	 * @return string
	 */
	final private function mimeType($path)
	{
		$info	= getimagesize($path);
		return $info['mime'];
	}

	/**
	 * Serves the rendered image
	 *
	 * @since 2.0
	 */
	final private function serveRenderedImage()
	{
		// Cache the image
		$this->cache();
		
		// Serve the file
		$this->serveFile(
			NULL,
			$this->rendered->data,
			gmdate('U'),
			$this->rendered->fileSize(),
			$this->rendered->mime,
			'rendered'
		);

		// Clean up memory
		$this->rendered->destroyImage();

		exit();
	}
	
	/**
	 * Serves a file
	 *
	 * @since 2.0
	 * @param string $imagePath Path to file to serve
	 * @param string $data Data of file to serve
	 * @param integer $lastModified Timestamp of when the file was last modified
	 * @param string $mimeType
	 * @param string $SLIRheader
	 * @return string Image data
	 */
	final private function serveFile($imagePath, $data, $lastModified, $length, $mimeType, $SLIRHeader)
	{
		if ($imagePath != NULL)
		{
			if ($lastModified == NULL)
				$lastModified	= filemtime($imagePath);
			if ($length == NULL)
				$length			= filesize($imagePath);
			if ($mimeType == NULL)
				$mimeType		= $this->mimeType($imagePath);
		}
		else if ($length == NULL)
		{
			$length		= strlen($data);
		} // if
		
		// Serve the headers
		$this->serveHeaders(
			$this->lastModified($lastModified),
			$mimeType,
			$length,
			$SLIRHeader
		);
		
		// Read the image data into memory if we need to
		if ($data == NULL)
			$data	= file_get_contents($imagePath);

		// Send the image to the browser in bite-sized chunks
		$chunkSize	= 1024 * 8;
		$fp			= fopen('php://memory', 'r+b');
		fwrite($fp, $data);
		rewind($fp);
		while (!feof($fp))
		{
			echo fread($fp, $chunkSize);
			flush();
		} // while
		fclose($fp);
		
		return $data;
	}

	/**
	 * Serves headers for file for optimal browser caching
	 *
	 * @since 2.0
	 * @param string $lastModified Time when file was last modified in 'D, d M Y H:i:s' format
	 * @param string $mimeType
	 * @param integer $fileSize
	 * @param string $SLIRHeader
	 */
	final private function serveHeaders($lastModified, $mimeType, $fileSize, $SLIRHeader)
	{
		header("Last-Modified: $lastModified");
		header("Content-Type: $mimeType");
		header("Content-Length: $fileSize");

		// Lets us easily know whether the image was rendered from scratch,
		// from the cache, or served directly from the source image
		header("Content-SLIR: $SLIRHeader");

		// Keep in browser cache how long?
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + SLIR_BROWSER_CACHE_EXPIRES_AFTER_SECONDS) . ' GMT');

		// Public in the Cache-Control lets proxies know that it is okay to
		// cache this content. If this is being served over HTTPS, there may be
		// sensitive content and therefore should probably not be cached by
		// proxy servers.
		header('Cache-Control: max-age=' . SLIR_BROWSER_CACHE_EXPIRES_AFTER_SECONDS . ', public');

		$this->doConditionalGet($lastModified);

		// The "Connection: close" header allows us to serve the file and let
		// the browser finish processing the script so we can do extra work
		// without making the user wait. This header must come last or the file
		// size will not properly work for images in the browser's cache
		header('Connection: close');
	}

	/**
	 * Converts a UNIX timestamp into the format needed for the Last-Modified
	 * header
	 *
	 * @since 2.0
	 * @param integer $timestamp
	 * @return string
	 */
	final private function lastModified($timestamp)
	{
		return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
	}

	/**
	 * Checks the to see if the file is different than the browser's cache
	 *
	 * @since 2.0
	 * @param string $lastModified
	 */
	final private function doConditionalGet($lastModified)
	{
		$ifModifiedSince = (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) ?
			stripslashes($_SERVER['HTTP_IF_MODIFIED_SINCE']) :
			FALSE;

		if (!$ifModifiedSince || $ifModifiedSince != $lastModified)
			return;

		// Nothing has changed since their last request - serve a 304 and exit
		header('HTTP/1.1 304 Not Modified');

		// Serve a "Connection: close" header here in case there are any
		// shutdown functions that have been registered with
		// register_shutdown_function()
		header('Connection: close');

		exit();
	}

} // class SLIR

// old pond
// a frog jumps
// the sound of water

// �Matsuo Basho
?>