<?php

    namespace IdnoPlugins\Twitter {

        class Main extends \Idno\Common\Plugin
        {

            function registerPages()
            {
                // Auth URL
                \Idno\Core\site()->addPageHandler('twitter/auth', '\IdnoPlugins\Twitter\Pages\Auth');
                // Deauth URL
                \Idno\Core\site()->addPageHandler('twitter/deauth', '\IdnoPlugins\Twitter\Pages\Deauth');
                // Register the callback URL
                \Idno\Core\site()->addPageHandler('twitter/callback', '\IdnoPlugins\Twitter\Pages\Callback');
                // Register admin settings
                \Idno\Core\site()->addPageHandler('admin/twitter', '\IdnoPlugins\Twitter\Pages\Admin');
                // Register settings page
                \Idno\Core\site()->addPageHandler('account/twitter', '\IdnoPlugins\Twitter\Pages\Account');

                /** Template extensions */
                // Add menu items to account & administration screens
                \Idno\Core\site()->template()->extendTemplate('admin/menu/items', 'admin/twitter/menu');
                \Idno\Core\site()->template()->extendTemplate('account/menu/items', 'account/twitter/menu');
                \Idno\Core\site()->template()->extendTemplate('onboarding/connect/networks', 'onboarding/connect/twitter');
            }

            function registerEventHooks()
            {

                \Idno\Core\site()->syndication()->registerService('twitter', function () {
                    return $this->hasTwitter();
                }, array('note', 'article', 'image', 'media', 'rsvp', 'bookmark'));

                \Idno\Core\site()->addEventHook('user/auth/success', function (\Idno\Core\Event $event) {
                    if ($this->hasTwitter()) {
                        $twitter = \Idno\Core\site()->session()->currentUser()->twitter;
                        if (is_array($twitter)) {
                            foreach($twitter as $username => $details) {
                                if (!in_array($username, ['user_token','user_secret','screen_name'])) {
                                    \Idno\Core\site()->syndication()->registerServiceAccount('twitter', $username, $username);
                                }
                            }
                            if (array_key_exists('user_token', $twitter)) {
                                \Idno\Core\site()->syndication()->registerServiceAccount('twitter', $twitter['screen_name'], $twitter['screen_name']);
                            }
                        }
                    }
                });

                // Activate syndication automatically, if replying to twitter
                \Idno\Core\site()->addEventHook('syndication/selected/twitter', function (\Idno\Core\Event $event) {
                    $eventdata = $event->data();

                    if (!empty($eventdata['reply-to'])) {
                        $replyto = $eventdata['reply-to'];
                        if (!is_array($replyto))
                            $replyto = [$replyto];

                        foreach ($replyto as $url) {
                            if (strpos(parse_url($url)['host'], 'twitter.com')!==false)
                                $event->setResponse(true);
                        }
                    }
                });

                // Push "notes" to Twitter
                \Idno\Core\site()->addEventHook('post/note/twitter', function (\Idno\Core\Event $event) {
                    $eventdata = $event->data();
                    if ($this->hasTwitter()) {
                        $object      = $eventdata['object'];
                        if (!empty($eventdata['syndication_account'])) {
                            $twitterAPI  = $this->connect($eventdata['syndication_account']);
                        } else {
                            $twitterAPI  = $this->connect();
                        }
                        $status_full = trim($object->getDescription());
                        $status      = preg_replace('/<[^\>]*>/', '', $status_full); //strip_tags($status_full);
                        $status      = str_replace("\r", '', $status);

                        // Add link to original post, if IndieWeb references have been requested
                        if (!substr_count($status, \Idno\Core\site()->config()->host) && \Idno\Core\site()->config()->indieweb_reference) {
                            $status .= ' ' . $object->getShortURL();
                        }

                        // Get links at this stage
                        preg_match_all('/((ht|f)tps?:\/\/[^\s\r\n\t<>"\'\(\)]+)/i', $status_full, $matches);

                        global $url_matches; // ugh
                        preg_match_all('/((ht|f)tps?:\/\/[^\s\r\n\t<>"\'\(\)]+)/i', $status, $url_matches);

                        $count_status = preg_replace('/((ht|f)tps?:\/\/[^\s\r\n\t<>"\'\(\)]+)/i', '12345678901234567890123', $status);

                        $count_status = trim($count_status);

                        if (mb_strlen($count_status) > 140) {
                            $count_status = substr($count_status, 0, 117);
                            if ($count_status[mb_strlen($count_status) - 1] != ' ') {
                                $count_status = substr($count_status, 0, strrpos($count_status, ' '));
                            }
                            $count_status = preg_replace_callback('/12345678901234567890123/', function ($callback) {
                                global $status_update_url_num;
                                global $status_url_matches;
                                if (empty($status_update_url_num)) {
                                    $status_update_url_num = 0;
                                }
                                if (!empty($status_url_matches[0][$status_update_url_num])) {
                                    return $status_url_matches[0][$status_update_url_num];
                                }
                                $status_update_url_num++;

                                return '';
                            }, $count_status);
                            $count_status .= ' .. ' . $object->getSyndicationURL();
                            $status = $count_status;
                        }

                        $status = preg_replace('/[ ]{2,}/', ' ', $status);

                        $params = array(
                            'status' => trim($status)
                        );

                        // Find any Twitter status IDs in case we need to mark this as a reply to them
                        if (!empty($matches[0])) {
                            foreach ($matches[0] as $match) {
                                if (parse_url($match, PHP_URL_HOST) == 'twitter.com') {
                                    preg_match('/[0-9]{8,}/', $match, $status_matches);
                                    $params['in_reply_to_status_id'] = $status_matches[0];
                                }
                            }
                        }

                        $response = $twitterAPI->request('POST', $twitterAPI->url('1.1/statuses/update'), $params);
                        if (!empty($twitterAPI->response['response'])) {
                            if ($json = json_decode($twitterAPI->response['response'])) {
                                if (!empty($json->id_str)) {
                                    $object->setPosseLink('twitter', 'https://twitter.com/' . $json->user->screen_name . '/status/' . $json->id_str, '@' . $json->user->screen_name);
                                    $object->save();
                                } else {
                                    \Idno\Core\site()->logging()->log("Nothing was posted to Twitter: " . var_export($json,true));
                                    \Idno\Core\site()->logging()->log("Twitter tokens: " . var_export(\Idno\Core\site()->session()->currentUser()->twitter,true));
                                }
                            } else {
                                \Idno\Core\site()->logging()->log("Bad JSON from Twitter: " . var_export($json,true));
                            }
                        }
                    }
                });

                // Function for articles, RSVPs etc
                $article_handler = function (\Idno\Core\Event $event) {
                    if ($this->hasTwitter()) {
                        $eventdata = $event->data();
                        $object     = $eventdata['object'];
                        if (!empty($eventdata['syndication_account'])) {
                            $twitterAPI  = $this->connect($eventdata['syndication_account']);
                        } else {
                            $twitterAPI  = $this->connect();
                        }
                        $status     = $object->getTitle();
                        if (mb_strlen($status) > 110) { // Trim status down if required
                            $status = substr($status, 0, 106) . ' ...';
                        }
                        $status .= ' ' . $object->getSyndicationURL();
                        $params = array(
                            'status' => $status
                        );

                        $response = $twitterAPI->request('POST', $twitterAPI->url('1.1/statuses/update'), $params);

                        if (!empty($twitterAPI->response['response'])) {
                            if ($json = json_decode($twitterAPI->response['response'])) {
                                if (!empty($json->id_str)) {
                                    $object->setPosseLink('twitter', 'https://twitter.com/' . $json->user->screen_name . '/status/' . $json->id_str, '@' . $json->user->screen_name);
                                    $object->save();
                                }  else {
                                    \Idno\Core\site()->logging()->log("Nothing was posted to Twitter: " . var_export($json,true));
                                }
                            } else {
                                \Idno\Core\site()->logging()->log("Bad JSON from Twitter: " . var_export($json,true));
                            }
                        }

                    }
                };

                // Push "articles" and "rsvps" to Twitter
                \Idno\Core\site()->addEventHook('post/article/twitter', $article_handler);
                \Idno\Core\site()->addEventHook('post/rsvp/twitter', $article_handler);
                \Idno\Core\site()->addEventHook('post/bookmark/twitter', $article_handler);

                // Push "media" to Twitter
                \Idno\Core\site()->addEventHook('post/media/twitter', function (\Idno\Core\Event $event) {
                    if ($this->hasTwitter()) {
                        $eventdata = $event->data();
                        $object     = $eventdata['object'];
                        if (!empty($eventdata['syndication_account'])) {
                            $twitterAPI  = $this->connect($eventdata['syndication_account']);
                        } else {
                            $twitterAPI  = $this->connect();
                        }
                        $status     = $object->getTitle();
                        if (mb_strlen($status) > 110) { // Trim status down if required
                            $status = substr($status, 0, 106) . ' ...';
                        }
                        $status .= ' ' . $object->getURL();
                        $params = array(
                            'status' => $status
                        );

                        $response = $twitterAPI->request('POST', $twitterAPI->url('1.1/statuses/update'), $params);

                        if (!empty($twitterAPI->response['response'])) {
                            if ($json = json_decode($twitterAPI->response['response'])) {
                                if (!empty($json->id_str)) {
                                    $object->setPosseLink('twitter', 'https://twitter.com/' . $json->user->screen_name . '/status/' . $json->id_str, '@' . $json->user->screen_name);
                                    $object->save();
                                } else {
                                    \Idno\Core\site()->logging()->log("Nothing was posted to Twitter: " . var_export($json,true));
                                }
                            } else {
                                \Idno\Core\site()->logging()->log("Bad JSON from Twitter: " . var_export($json,true));
                            }
                        }

                    }
                });

                // Push "images" to Twitter
                \Idno\Core\site()->addEventHook('post/image/twitter', function (\Idno\Core\Event $event) {
                    if ($this->hasTwitter()) {
                        $eventdata = $event->data();
                        $object     = $eventdata['object'];
                        if (!empty($eventdata['syndication_account'])) {
                            $twitterAPI  = $this->connect($eventdata['syndication_account']);
                        } else {
                            $twitterAPI  = $this->connect();
                        }
                        $status     = $object->getTitle();
                        if ($status == 'Untitled') {
                        	$status = '';
                        }
                        if (mb_strlen($status) > 110) { // Trim status down if required
                            $status = substr($status, 0, 106) . ' ...';
                        }

                        // Let's first try getting the thumbnail
                        if (!empty($object->thumbnail_id)) {
                            if ($thumb = (array)\Idno\Entities\File::getByID($object->thumbnail_id)) {
                                $attachments = array($thumb['file']);
                            }
                        }

                        // No? Then we'll use the main event
                        if (empty($attachments)) {
                            $attachments = $object->getAttachments();
                        }

                        if (!empty($attachments)) {
                        foreach ($attachments as $attachment) {
                            if ($bytes = \Idno\Entities\File::getFileDataFromAttachment($attachment)) {
                                $media = array();
                                $filename = tempnam(sys_get_temp_dir(), 'idnotwitter');
                                file_put_contents($filename, $bytes);
                                $media['media_data'] = base64_encode(file_get_contents($filename));
                                $params = $media;
                                $response = $twitterAPI->request('POST', ('https://upload.twitter.com/1.1/media/upload.json'), $params, true, true);
                                \Idno\Core\site()->logging()->log($response);
                                $json = json_decode($twitterAPI->response['response']);
                                if (isset($json->media_id_string)) {
                                    $media_id[] = $json->media_id_string;
                                    error_log("Twitter media_id : " . $json->media_id);
                                } else {
                                	/*{"errors":[{"message":"Sorry, that page does not exist","code":34}]}*/
                                	if (isset($json->errors)){
                                		$message[] = $json->errors;
                                		$twitter_error = $message['message']." (code ".$message['code'].")";
                                	}
                                    \Idno\Core\site()->session()->addMessage("We couldn't upload photo to Twitter. Twitter response: {$twitter_error}.");
                                }
                            }
                        }
                    }

                    if (!empty($media_id)) {
                        $id = implode(',', $media_id);
                        $params = array('status' => $status,
                            'media_ids' => "{$id}");
                        try {
                            $response = $twitterAPI->request('POST', ('https://api.twitter.com/1.1/statuses/update.json'), $params, true, false);
                            \Idno\Core\site()->logging()->log("JSON from Twitter: " . var_export($twitterAPI->response['response'], true));
                        } catch (\Exception $e) {
                            \Idno\Core\site()->logging()->log($e);
                        }
                    }
                        /*$code = $twitterAPI->request( 'POST','https://upload.twitter.com/1.1/statuses/update_with_media',
                            $params,
                            true, // use auth
                            true  // multipart
                        );*/

                        @unlink($filename);

                        if (!empty($twitterAPI->response['response'])) {
                            if ($json = json_decode($twitterAPI->response['response'])) {
                                if (!empty($json->id_str)) {
                                    $object->setPosseLink('twitter', 'https://twitter.com/' . $json->user->screen_name . '/status/' . $json->id_str, '@' . $json->user->screen_name);
                                    $object->save();
                                } else {
                                    \Idno\Core\site()->logging()->log("Nothing was posted to Twitter: " . var_export($json,true));
                                }
                            } else {
                                \Idno\Core\site()->logging()->log("Bad JSON from Twitter: " . var_export($json,true));
                            }
                        }

                    }
                });
            }

            /**
             * Retrieve the OAuth authentication URL for the API
             * @return string
             */
            function getAuthURL()
            {
                $twitter    = $this;
                $twitterAPI = $twitter->connect();
                if (!$twitterAPI) {
                    return '';
                }
                $code       = $twitterAPI->request('POST', $twitterAPI->url('oauth/request_token', ''), array('oauth_callback' => \Idno\Core\site()->config()->getDisplayURL() . 'twitter/callback', 'x_auth_access_type' => 'write'));
                if ($code == 200) {
                    $oauth = $twitterAPI->extract_params($twitterAPI->response['response']);
                    \Idno\Core\site()->session()->set('oauth', $oauth); // Save OAuth to the session
                    $oauth_url = $twitterAPI->url("oauth/authorize", '') . "?oauth_token={$oauth['oauth_token']}";
                } else {
                    $oauth_url = '';
                }

                return $oauth_url;
            }

            /**
             * Returns a new Twitter OAuth connection object, if credentials have been added through administration
             * and it's possible to connect
             *
             * @param $username If supplied, attempts to connect with this username
             * @return bool|\tmhOAuth
             */
            function connect($username = false)
            {
                require_once(dirname(__FILE__) . '/external/tmhOAuth/tmhOAuth.php');
                require_once(dirname(__FILE__) . '/external/tmhOAuth/tmhUtilities.php');
                if (!empty(\Idno\Core\site()->config()->twitter)) {
                    $params = array(
                        'consumer_key'    => \Idno\Core\site()->config()->twitter['consumer_key'],
                        'consumer_secret' => \Idno\Core\site()->config()->twitter['consumer_secret'],
                    );
                    if (!empty($username) && !empty(\Idno\Core\site()->session()->currentUser()->twitter[$username])) {
                        $params = array_merge($params, \Idno\Core\site()->session()->currentUser()->twitter[$username]);
                    } else if (!empty(\Idno\Core\site()->session()->currentUser()->twitter['user_token']) && ($username == \Idno\Core\site()->session()->currentUser()->twitter['screen_name'] || empty($username))) {
                        $params['user_token'] = \Idno\Core\site()->session()->currentUser()->twitter['user_token'];
                        $params['user_secret'] = \Idno\Core\site()->session()->currentUser()->twitter['user_secret'];
                        $params['screen_name'] = \Idno\Core\site()->session()->currentUser()->twitter['screen_name'];
                    }

                    return new \tmhOAuth($params);
                }

                return false;
            }

            /**
             * Can the current user use Twitter?
             * @return bool
             */
            function hasTwitter()
            {
                if (!\Idno\Core\site()->session()->currentUser()) {
                    return false;
                }
                if (!empty(\Idno\Core\site()->session()->currentUser()->twitter)) {
                    if (is_array(\Idno\Core\site()->session()->currentUser()->twitter)) {
                        $accounts = 0;
                        foreach(\Idno\Core\site()->session()->currentUser()->twitter as $username => $value) {
                            if ($username != 'user_token') {
                                $accounts++;
                            }
                        }
                        if ($accounts > 0) {
                            return true;
                        }
                    }
                    return true;
                }

                return false;
            }

        }

    }
