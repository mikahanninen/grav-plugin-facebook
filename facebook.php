<?php
namespace Grav\Plugin;

use \Grav\Common\Plugin;
use Grav\Common\Data\Data;
use Grav\Common\Page\Page;
use Grav\Common\GPM\Response;
use \DateTime;

require __DIR__ . '/classes/FacebookEvents.php';
use Grav\Plugin\Facebook\FacebookEvents;

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
        if ($this->config->get('plugins.facebook.use_unitegallery_plugin')) {
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

        $events = new FacebookEvents($config);

        // Generate API url
        $url = $events->getGraphUrl('fields=cover,start_time,end_time,name,description,place');

        $this->addEvent($events->parseEvents(Response::get($url), 'original_start'));

        $title = $config->get('facebook_event_settings.section_title');
        $this->template_event_vars = [
          'sectionTitleRaw' => $title,
          'sectionTitle' => '<h3 class="heading">' . $title . '</h3>',
          'showCover' => ($config->get('facebook_event_settings.show_cover') == '1') ? true : false,
          'events' => $this->events,
          'count' => $config->get('facebook_event_settings.count', 7)
        ];

        $output = $this->grav['twig']->twig()
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

        $album_name =
            empty($album_name_from_page) ? $config->get('facebook_album_settings.album_name')
                : $album_name_from_page;

        // Generate API url
        $url =
            'https://graph.facebook.com/' . $config->get('facebook_page_settings.page_id')
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
