<?php
	
	/**
	 * This file is part of the PHP Video Toolkit v2 package.
	 *
	 * @author Oliver Lillie (aka buggedcom) <publicmail@buggedcom.co.uk>
	 * @license Dual licensed under MIT and GPLv2
	 * @copyright Copyright (c) 2008 Oliver Lillie <http://www.buggedcom.co.uk>
	 * @package PHPVideoToolkit V2
	 * @version 2.0.0.a
	 * @uses ffmpeg http://ffmpeg.sourceforge.net/
	 */
	 
	namespace PHPVideoToolkit;

	/**
	 * This class provides generic data parsing for the output from FFmpeg from specific
	 * media files. Parts of the code borrow heavily from Jorrit Schippers version of 
	 * PHPVideoToolkit v 0.1.9.
	 *
	 * @access public
	 * @author Oliver Lillie
	 * @author Jorrit Schippers
	 * @package default
	 */
	class Video extends Media
	{
		protected $_extracting_frames;
		protected $_extracting_audio;
		
		public function __construct($video_file_path, VideoFormat $video_input_format=null, $ffmpeg_path, $temp_directory)
		{
			parent::__construct($video_file_path, $video_input_format, $ffmpeg_path, $temp_directory);
			
//			validate this media file is a video file
			$type = $this->readType();
			if($type !== 'video')
			{
				throw new Exception('You cannot use an \\PHPVideoToolkit\\Video for "'.$video_file_path.'" as the file is not a video file. It is reported to be a '.$type);
			}
			
			$this->_extracting_frames = false;
			$this->_extracting_audio = false;
		}
		
		/**
		 * Returns the default (empty) input format for the type of media object this class is.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param string $type Either input for an input format or output for an output format.
		 * @return Format
		 */
		public function getDefaultFormat($type)
		{
			return $this->_getDefaultFormat($type, 'VideoFormat');
		}
		
		public function extractAudio()
		{
			$this->_extracting_audio = true;
			
			return $this;
		}
		
		public function extractFrame(Timecode $timecode)
		{
			$this->extractSegment($timecode, $timecode);
			$this->_extracting_frames = true;
			
			return $this;
		}
		
		protected function _savePreProcess(Format &$output_format, &$save_path, $overwrite, ProgressHandlerAbstract &$progress_handler=null)
		{
			parent::_savePreProcess($output_format, $save_path, $overwrite, $progress_handler);
			
//			if we are splitting the output
			if(empty($this->_split_options) === false)
			{
				$options = $output_format->getFormatOptions();
			
//				if we are splitting we need to add certain commands to make it work.
//				for video, we need to ensure that both audio and video codecs are set.
				if($this->readHasAudio() === true && empty($options['audio_codec']) === true)
				{
					$data = $this->readAudioComponent(); 
					// TODO checks for empty name
					// TODO check for encode availability
					$this->_process->addCommand('-acodec', $data['codec']['name']);
				}
				if(empty($options['video_codec']) === true)
				{
					$data = $this->readVideoComponent();
					// TODO checks for empty name
					// TODO check for encode availability
					$this->_process->addCommand('-vcodec', $data['codec']['name']);
				}
			}
		}
		
		/**
		 * Process the output format just before the it is compiled into commands.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param Format &$output_format 
		 * @return void
		 */
		protected function _processOutputFormat(Format &$output_format, &$save_path)
		{
			parent::_processOutputFormat($output_format, $save_path);
			
//			turn off the related options.
			if($this->_extracting_audio === true)
			{
				$output_format->disableVideo();
			}
			if($this->_extracting_frames === true)
			{
				$output_format->disableAudio();
			}
			
//			check for conflictions with having both audio and video disabled.
			$options = $output_format->getFormatOptions();
			if($options['disable_audio'] === true && $options['disable_video'] === true)
			{
				throw new Exception('Unable to process output format to send to ffmpeg as both audio and video are disabled.');
			}

//			check to see if output is a gif, if that is the case we need to change the output format and path so that
//			we output multiple images and then after the output, post process them into a single animated gif.
//			Reason being ffmpeg is let down by crappy animated gif output.
			if((empty($options['format']) === true && strtolower(pathinfo($save_path, PATHINFO_EXTENSION)) === 'gif') || $options['format'] === 'gif')
			{
//				if the frame rate has not been set, set a sane frame rate for an animated gif.
				if(empty($options['video_frame_rate']) === true)
				{
					$frame_rate = $this->getFrameRate();
					if($frame_rate > 12)
					{
						$output_format->setVideoFrameRate(12);
					}
				}
				
//				as we are outputting frames we want the jpeg format for each frame
				$output_format->setFormat(null);
				
//				update the pathway to include indexed output so that it outputs multiple frames.
				$pathinfo = pathinfo($save_path);
				$save_path = $pathinfo['dirname'].DIRECTORY_SEPARATOR.$pathinfo['filename'].'-%12index.png';
				
//				register the post process for tidying up stuff.
				//$gif = Factory::animatedGif();
				//$this->_registerSavePostProcess(array($gif, 'createFrom'), 'toolkit');
			}

// 			check to see if an aspect ratio is set, if it is correct the width and heights to reflect that aspect ratio.
// 			This isn't strictly needed it is purely for informational purposes that this is done, because if the width is not
// 			inline with what is should be according to the aspect ratio ffmpeg will report the wrong final width and height
// 			when using it to lookup information about the file.
			$aspect_ratio = $options['video_aspect_ratio'];
			if(empty($aspect_ratio) === false)
			{
				$dimensions = $options['video_dimensions'];
				if(empty($dimensions) === true)
				{
					$dimensions = $this->getDimensions();
					if(empty($dimensions) === false)
					{
						$dimensions['auto_adjust_dimensions'] = $aspect_ratio['auto_adjust_dimensions'];
						$dimensions['force_aspect'] = true;
						$options['video_dimensions'] = $dimensions;
					}
				}
				if(empty($dimensions) === false)
				{
					if(strpos($aspect_ratio['ratio'], ':') !== false)
					{
						$ratio = explode(':', $aspect_ratio['ratio']);
						$ratio = $ratio[0] / $ratio[1];
						$new_width = round($dimensions['height'] * $ratio);
// 						make sure new width is an even number
						$ceiled = ceil($new_width);
						$new_width = $ceiled % 2 !== 0 ? floor($new_width) : $ceiled;
						if($new_width !== $dimensions['width'])
						{
							$output_format->setVideoDimensions($new_width, $dimensions['height'], $dimensions['auto_adjust_dimensions'], $dimensions['force_aspect']);
							$options = $output_format->getFormatOptions();
						}
					}
					else if(strpos($aspect_ratio['ratio'], '.') !== false)
					{
						$ratio = floatval($aspect_ratio['ratio']);
						$new_width = $dimensions['height'] * $ratio;
// 						make sure new width is an even number
						$ceiled = ceil($new_width);
						$new_width = $ceiled % 2 !== 0 ? floor($new_width) : $ceiled;
						if($new_width !== $dimensions['width'])
						{
							$output_format->setVideoDimensions($new_width, $dimensions['height'], $dimensions['auto_adjust_dimensions'], $dimensions['force_aspect']);
							$options = $output_format->getFormatOptions();
						}
					}
				}
			}
			
//			check the video dimensions to see if we need to post process the dimensions
			$dimensions = $options['video_dimensions'];
			if(empty($dimensions) === false && $dimensions['auto_adjust_dimensions'] === true)
			{
//				get the optimal dimensions for this video based on the aspect ratio
				$optimal_dimensions = $this->getOptimalDimensions($dimensions['width'], $dimensions['height'], $dimensions['force_aspect']);
				if($dimensions['width'] !== $optimal_dimensions['padded_width'] || $dimensions['height'] !== $optimal_dimensions['padded_height'])
				{
					$output_format->setVideoDimensions($optimal_dimensions['padded_width'], $optimal_dimensions['padded_height']);
				}
				
//				check to see if we have to apply any padding.
				if($optimal_dimensions['pad_top'] > 0 || $optimal_dimensions['pad_right'] > 0 || $optimal_dimensions['pad_bottom'] > 0 || $optimal_dimensions['pad_left'] > 0)
				{
					$output_format->setVideoPadding($optimal_dimensions['pad_top'], $optimal_dimensions['pad_right'], $optimal_dimensions['pad_bottom'], $optimal_dimensions['pad_left'], $optimal_dimensions['padded_width'], $optimal_dimensions['padded_height']);
				}
			}
		}
		
		/**
		 * Takes in a set of video dimensions - original and target - and returns the optimal conversion
		 * dimensions.  It will always return the smaller of the original or target dimensions.
		 * For example: original dimensions of 320x240 and target dimensions of 640x480.
		 * The result will be 320x240 because converting to 640x480 would be a waste of disk
		 * space, processing, and bandwidth (assuming these videos are to be downloaded).
		 * 
		 * @param $target_width:       The width of the video file which we will be converting to.
		 * @param $target_height:      The height of the video file which we will be converting to.
		 * @param $force_aspect:       Boolean value of whether or not to force conversion to the target's
		 *                 aspect ratio using padding (so the video isn't stretched).  If false, the
		 *                conversion dimensions will retain the aspect ratio of the original.
		 *                Optional parameter.  Defaults to true.
		 * @return: An array containing the size and padding information to be used for conversion.
		 *       Format:
		 *       Array
		 *       (
		 *           [width] => int
		 *           [height] => int
		 *           [pad_top] => int // top padding (if applicable)
		 *           [pad_bottom] => int // bottom padding (if applicable)
		 *           [pad_left] => int // left padding (if applicable)
		 *           [pad_right] => int // right padding (if applicable)
		 *       )
		 *
		 * @access public
		 * @author Herr K
		 * @link http://stackoverflow.com/q/3988011/194480
		 * @return void
		 */
		public function getOptimalDimensions($target_width, $target_height, $force_aspect=true)
	    {
			$dimensions = $this->getDimensions();
			if(empty($dimensions) === true)
			{
				throw new Exception('Unable to read the videos dimensions.');
			}
			
			$original_width = $dimensions['width'];
			$original_height = $dimensions['height'];	
			
//			Array to be returned by this function
	        $target = array(
	        	'padded_width' => $original_width,
	        	'padded_height' => $original_height,
	        	'video_width' => $original_width,
	        	'video_height' => $original_height,
	        	'pad_top' => 0,
	        	'pad_right' => 0,
	        	'pad_bottom' => 0,
	        	'pad_left' => 0,
	        );

//			Target aspect ratio (width / height)
	        $aspect = $target_width / $target_height;

//			Target reciprocal aspect ratio (height / width)
	        $raspect = $target_height / $target_width;

//			Aspect ratio is different
	        if($original_width/$original_height !== $aspect)
	        {
// 				Width is the greater of the two dimensions relative to the target dimensions
	            if($original_width/$original_height > $aspect)
	            {
// 					Original video is smaller.  Scale down dimensions for conversion
	                if($original_width < $target_width)
	                {
	                    $target_width = $original_width;
	                    $target_height = round($raspect * $target_width);
	                }
					
// 					Calculate height from width
	                $original_height = round($original_height / $original_width * $target_width);
	                $original_width = $target_width;
	                if($force_aspect === true)
	                {
// 						Pad top and bottom
	                    $dif = round(($target_height - $original_height) / 2);
	                    $target['pad_top'] = $dif;
	                    $target['pad_bottom'] = $dif;
	                }
	            }
	            else
	            {
// 					Height is the greater of the two dimensions relative to the target dimensions
	                if($original_height < $target_height)
	                {
// 						Original video is smaller.  Scale down dimensions for conversion
	                    $target_height = $original_height;
	                    $target_width = round($aspect * $target_height);
	                }
					
//					Calculate width from height
	                $original_width = round($original_width / $original_height * $target_height);
	                $original_height = $target_height;
	                if($force_aspect === true)
	                {
// 						Pad left and right
	                    $dif = round(($target_width - $original_width) / 2);
	                    $target['pad_left'] = $dif;
	                    $target['pad_right'] = $dif;
	                }
	            }
	        }
// 			The aspect ratio is the same
	        else
	        {
	            if($original_width !== $target_width)
	            {
// 					The original video is smaller.  Use its resolution for conversion
	                if($original_width < $target_width)
	                {
	                    $target_width = $original_width;
	                    $target_height = $original_height;
	                }
// 					The original video is larger,  Use the target dimensions for conversion
	                else
	                {
	                    $original_width = $target_width;
	                    $original_height = $target_height;
	                }
	            }
	        }
			
// 			Use the target_ vars because they contain dimensions relative to the target aspect ratio
	        if($force_aspect === true)
	        {
	            $target['padded_width'] = $target_width;
	            $target['padded_height'] = $target_height;
	        }
			else
			{
	            $target['padded_width'] = $original_width;
	            $target['padded_height'] = $original_height;
			}
			
	        return $target;
		}
		
		/**
		 * Returns any video information about the file if available.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns an array of found data, otherwise returns null.
		 */
		public function getDimensions($read_from_cache=true)
		{
			$video_data = parent::readVideoComponent($read_from_cache);
			return $video_data['dimensions'];
		}
		
		/**
		 * Returns any video information about the file if available.
		 *
		 * @access public
		 * @author Oliver Lillie
		 * @param boolean $read_from_cache 
		 * @return mixed Returns an array of found data, otherwise returns null.
		 */
		public function getFrameRate($read_from_cache=true)
		{
			$video_data = parent::readVideoComponent($read_from_cache);
			return $video_data['frame_rate'];
		}
	}
