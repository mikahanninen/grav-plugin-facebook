<?php
namespace Grav\Plugin;

use \Grav\Common\Plugin;
use Grav\Common\Data\Data;
use Grav\Common\Page\Page;
use Grav\Common\GPM\Response;
use \DateTime;

class FacebookPlugin extends Plugin {

    private $template_post_html = 'partials/facebook.post.html.twig';
    private $template_event_html = 'partials/facebook.event.html.twig';
    private $template_gallery_html = 'partials/facebook.gallery.html.twig';
    private $template_post_vars = [];
    private $template_event_vars = [];
    private $events = array();
    private $feeds = array();
    private $album;

    /**
     * Return a list of subscribed events.
     *
     * @return array    The list of events of the plugin of the form
     *                      'name' => ['method_name', priority].
     */
    public static function getSubscribedEvents() {
        return ['onPluginsInitialized' => ['onPluginsInitialized', 0],];
    }

    /**
     * Initialize configuration.
     */
    public function onPluginsInitialized() {
        $this->enable(['onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0]]);
    }

    /**
     * Add Twig Extensions.
     */
    public function onTwigInitialized() {
        $this->grav['twig']->twig->addFunction(new \Twig_SimpleFunction('facebook_posts',
            [$this, 'getFacebookPosts']));
        $this->grav['twig']->twig->addFunction(new \Twig_SimpleFunction('facebook_events',
            [$this, 'getFacebookEvents']));
        $this->grav['twig']->twig->addFunction(new \Twig_SimpleFunction('facebook_album',
            [$this, 'getFacebookAlbum']));
        if ($this->config->get('plugins.facebook.built_in_css')) {
            $this->grav['assets']->add('plugin://facebook/css/facebook.css');
        }
        if ($this->config->get('plugins.facebook.facebook_album_settings.use_unitegallery')) {
            $this->grav['assets']->add('jquery', 101);
            $this->grav['assets']->addJs('plugin://facebook/assets/unitegallery/js/unitegallery.min.js');
            $this->grav['assets']->addCss('plugin://facebook/assets/unitegallery/css/unite-gallery.css');
            // Theme asset css & js
            $themeName =
                $this->config->get('plugins.facebook.facebook_album_settings.unitegallery_theme');
            $this->grav['assets']->addJs('plugin://facebook/assets/unitegallery/themes/'
                . $themeName . '/ug-theme-' . $themeName . '.js');
            $this->grav['assets']->addCss('plugin://facebook/assets/unitegallery/themes/default/ug-theme-default.css');
        }
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths() {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }


    public function getFacebookPosts($filtered_by_tags_from_page = "") {
        /** @var Page $page */
        $page = $this->grav['page'];
        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        /** @var Data $config */
        $config = $this->mergeConfig($page, TRUE);

        $filter_by_tags =
            empty($filtered_by_tags_from_page)
                ? $config->get('facebook_page_settings.filter_by_tags')
                : $filtered_by_tags_from_page;
        // Generate API url
        $url =
            'https://graph.facebook.com/' . $config->get('facebook_page_settings.page_id')
            . '/?fields=feed{permalink_url,created_time,link,attachments,message}&access_token='
            . $config->get('facebook_common_settings.application_id') . '|'
            . $config->get('facebook_common_settings.application_secret');
        $results = Response::get($url);

        $this->parsePostResponse($results, $config, $filter_by_tags);

        $this->template_post_vars =
            ['pageLink' => 'https://www.facebook.com/'
                . $config->get('facebook_page_settings.page_name'),
                'sectionTitleRaw' => $config->get('facebook_page_settings.section_title'),
                'sectionTitle' => '<a href="https://www.facebook.com/'
                    . $config->get('facebook_page_settings.page_name') . '/"><h3 class="heading">'
                    . $config->get('facebook_page_settings.section_title') . '</h3></a>',
                'feed' => $this->feeds,
                'count' => empty($config->get('facebook_page_settings.count')) ? 7
                    : $config->get('facebook_page_settings.count')];

        $output =
            $this->grav['twig']->twig()
                ->render($this->template_post_html, $this->template_post_vars);
        return $output;
    }

    public function getFacebookEvents() {
        /** @var Page $page */
        $page = $this->grav['page'];
        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        /** @var Data $config */
        $config = $this->mergeConfig($page, TRUE);

        $events_page_id =
            empty($config->get('facebook_event_settings.events_page_id'))
                ? $config->get('facebook_page_settings.page_id')
                : $config->get('facebook_event_settings.events_page_id');
        // Generate API url
        $url =
            'https://graph.facebook.com/' . $events_page_id
            . '/events?fields=cover,start_time,end_time,name,description,place&access_token='
            . $config->get('facebook_common_settings.application_id') . '|'
            . $config->get('facebook_common_settings.application_secret');
        $results = Response::get($url);
        $this->parseEventResponse($results, $config);

        $this->template_event_vars =
            ['sectionTitleRaw' => $config->get('facebook_event_settings.section_title'),
                'sectionTitle' => '<h3 class="heading">'
                    . $config->get('facebook_event_settings.section_title') . '</h3>',
                'showCover' => ($config->get('facebook_event_settings.show_cover') == '1') ? true
                    : false, 'events' => $this->events,
                'count' => empty($config->get('facebook_event_settings.count')) ? 7
                    : $config->get('facebook_event_settings.count')];

        $output =
            $this->grav['twig']->twig()
                ->render($this->template_event_html, $this->template_event_vars);
        return $output;
    }

    public function getFacebookAlbum($album_name_from_page = "") {
        /** @var Page $page */
        $page = $this->grav['page'];
        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        /** @var Data $config */
        $config = $this->mergeConfig($page, TRUE);

        $album_page_id =
        empty($config->get('facebook_album_settings.album_page_id'))
            ? $config->get('facebook_page_settings.page_id')
            : $config->get('facebook_album_settings.album_page_id');

        $album_name =
            empty($album_name_from_page) ? $config->get('facebook_album_settings.album_name')
                : $album_name_from_page;

        // Generate API url
        $url =
            'https://graph.facebook.com/' . $album_page_id
            . '/albums?access_token=' . $config->get('facebook_common_settings.application_id')
            . '|' . $config->get('facebook_common_settings.application_secret');
        $results = Response::get($url);
        $this->parseGalleryResponse($results, $config, $album_name);
        $template_event_vars =
            ['album' => $this->album,
                'useUnitePlugin' => ($config->get('facebook_album_settings.use_unitegallery')
                    == '1') ? true : false,];
        $output =
            $this->grav['twig']->twig()->render($this->template_gallery_html, $template_event_vars);
        return $output;
    }

    private function parsePostResponse($json, $config, $tags_string) {
        $r = array();
        $content = json_decode($json);
        
        $count = $config->get('facebook_page_settings.count');

        foreach ($content->feed->data as $val) {
            if (property_exists($val, 'message') && $this->tagsExist($tags_string, $val->message)) {
                $created_at = $val->created_time;
                $created_date_object = date_create($created_at);
                $formatted_date =
                    date_format($created_date_object,
                        $config->get('facebook_page_settings.date_format'));
                $image_html = "";
                $imageSrc = (property_exists($val, 'attachments')
                    && property_exists($val->attachments->data[0], 'media'))
                    ? $val->attachments->data[0]->media->image->src :
                        (property_exists($val, 'attachments')
                        && property_exists($val->attachments->data[0], 'subattachments')
                        && property_exists($val->attachments->data[0]->subattachments->data[0], 'media')
                        ? $val->attachments->data[0]->subattachments->data[0]->media->image->src : null
                    );

                if ($imageSrc) {
                    $image_html = "<figure>";
                    $image_html .= '<img class="media-object" src="' . $imageSrc . '">';
                    $image_html .= "</figure>";
                }

                $r[$count]['timeObject'] = $created_date_object;
                $r[$count]['time'] = $formatted_date;
                $r[$count]['image'] = $image_html;
                $r[$count]['imageSrc'] = $imageSrc;
                $r[$count]['message'] = nl2br($val->message);
                $r[$count]['link'] = $val->permalink_url;
                $this->addFeed($r);
                
                $count -= 1;
            }
        }
    }

    private function parseEventResponse($json, $config) {
        $r = array();
        $content = json_decode($json);

        foreach ($content->data as $val) {
            if (property_exists($val, 'start_time') && property_exists($val, 'end_time')) {
                $start_at = $val->start_time;
                $end_at = $val->end_time;
                $start_date_array = date_parse($start_at);
                $end_date_array = date_parse($end_at);

                $start_date_array['monthName'] =
                    strftime ('%B', mktime(0, 0, 0, $start_date_array['month'], 10));
                $start_date_array['dayName'] =
                    strftime ('%A', mktime(0, 0, 0, $start_date_array['month'], $start_date_array['day'],
                        $start_date_array['year']));
                $end_date_array['monthName'] =
                    strftime ('%B', mktime(0, 0, 0, $end_date_array['month'], 10));

                $r[$start_at]['original_start'] = $start_at;
                $r[$start_at]['original_end'] = $end_at;
                $start_date_array['hour'] =
                    ($start_date_array['hour'] == 0) ? '00' : $start_date_array['hour'];
                $start_date_array['minute'] =
                    ($start_date_array['minute'] == 0) ? '00' : $start_date_array['minute'];
                $end_date_array['hour'] =
                    ($end_date_array['hour'] == 0) ? '00' : $end_date_array['hour'];
                $end_date_array['minute'] =
                    ($end_date_array['minute'] == 0) ? '00' : $end_date_array['minute'];
                $r[$start_at]['start_time'] = $start_date_array;
                $r[$start_at]['end_time'] = $end_date_array;
                if ($this->isStartDaySameAsEnd($start_date_array, $end_date_array)) {
                    $r[$start_at]['period'] =
                        $start_date_array['dayName'] . ' ' . $start_date_array['hour'] . ':'
                        . $start_date_array['minute'];
                } else {
                    $r[$start_at]['period'] =
                        $start_date_array['day'] . '. ' . $start_date_array['monthName'] . ' '
                        . $start_date_array['year'] . ' - ' . $end_date_array['day'] . '. '
                        . $end_date_array['monthName'] . ' ' . $end_date_array['year'];
                }
                $r[$start_at]['event_link'] = $val->id;
                $r[$start_at]['name'] = nl2br($val->name);
                $r[$start_at]['place'] = '';
                $r[$start_at]['description'] = '';
                $r[$start_at]['cover'] = '';

                if (property_exists($val, 'cover')) {
                    $r[$start_at]['cover'] = $val->cover;
                }
                if (property_exists($val, 'place')) {
                    if (property_exists($val->place, 'name')) {
                        $r[$start_at]['place']['name'] = $val->place->name;
                    }
                    if (property_exists($val->place, 'location')) {
                        $city = '';
                        $country = '';
                        if (property_exists($val->place->location, 'city')) $city = $val->place->location->city;
                        if (property_exists($val->place->location, 'country')) $country = $val->place->location->country;
                        $r[$start_at]['place']['location'] = $city.' '.$country;
                    }
                }
                if (property_exists($val, 'description')) {
                    $r[$start_at]['description'] = $val->description;
                }
                $this->addEvent($r);
            }
        }
    }

    private function getAlbumPhotos($albumId) {
        /** @var Page $page */
        $page = $this->grav['page'];
        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        /** @var Data $config */
        $config = $this->mergeConfig($page, TRUE);
        // Generate API url
        $url =
            'https://graph.facebook.com/' . $albumId . '/photos?fields=source&access_token='
            . $config->get('facebook_common_settings.application_id') . '|'
            . $config->get('facebook_common_settings.application_secret');
        $results = Response::get($url);
        $json = json_decode($results);
        return $json->data;
    }

    private function parseGalleryResponse($json, $config, $album_name) {
        $content = json_decode($json);
        foreach ($content->data as $val) {
            if (strcasecmp($val->name, $album_name) === 0) {
                $this->album = $val;
                $this->album->photos = $this->getAlbumPhotos($this->album->id);
                break;
            }
        }
    }


    private function isStartDaySameAsEnd($start, $end) {
        return (($start['year'] == $end['year']) && ($start['month'] == $end['month'])
            && ($start['day'] == $end['day']));
    }

    private function addFeed($result) {
        foreach ($result as $key => $val) {
            if (!isset($this->feeds[$key])) {
                $this->feeds[$key] = $val;
            }
        }
        krsort($this->feeds);
    }

    private function addEvent($result) {
        foreach ($result as $key => $val) {
            if (!isset($this->events[$key])) {
                $this->events[$key] = $val;
            }
        }
        krsort($this->events);
    }

    private function tagsExist($tags_string, $message) {
        if (empty($tags_string)) {
            return true;
        }
        $all_tags = explode(" ", $tags_string);
        foreach ($all_tags as $atag) {
            if (stripos($message, $atag) == FALSE) {
                return false;
            }
        }
        return true;
    }
}
