<?php
namespace Grav\Plugin;

use \Grav\Common\Plugin;
use Grav\Common\Data\Data;
use Grav\Common\Page\Page;
use Grav\Common\GPM\Response;

class FacebookPlugin extends Plugin {
    private $template_html = 'partials/facebook.html.twig';
    private $template_vars = [];
    /**
     * Return a list of subscribed events.
     *
     * @return array    The list of events of the plugin of the form
     *                      'name' => ['method_name', priority].
     */
    public static function getSubscribedEvents() {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * Initialize configuration.
     */
    public function onPluginsInitialized() {
        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0]
        ]);
    }

    /**
     * Add Twig Extensions.
     */
    public function onTwigInitialized() {
        $this->grav['twig']->twig->addFunction(new \Twig_SimpleFunction('facebook_posts', [$this, 'getFacebookPosts']));
        if ($this->config->get('plugins.facebook.built_in_css')) {
            $this->grav['assets']->add('plugin://facebook/css/facebook.css');
        }
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths() {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }


    public function getFacebookPosts() {
      /** @var Page $page */
      $page = $this->grav['page'];
      /** @var Twig $twig */
      $twig = $this->grav['twig'];
      /** @var Data $config */
      $config = $this->mergeConfig($page, TRUE);
      // Generate API url
      $url = 'https://graph.facebook.com/'.$config->get('feed_parameters.page_id').'/?fields=feed{permalink_url,created_time,link,attachments,message}&access_token='.$config->get('feed_parameters.application_id').'|'.$config->get('feed_parameters.application_secret');
      $results = Response::get($url);

      $this->parseResponse($results, $config);

      $this->template_vars = [
          'sectionTitle'  => '<a href="https://www.facebook.com/'.$config->get('feed_parameters.page_name').'/"><h3 class="heading">'.$config->get('feed_parameters.section_title').'</h3></a>',
          'feed'          => $this->feeds,
          'count'         => $config->get('feed_parameters.count')
      ];

      $output = $this->grav['twig']->twig()->render($this->template_html, $this->template_vars);

      return $output;
    }

    private function parseResponse($json, $config) {
      $r = array();
      $content = json_decode($json);

      foreach($content->feed->data as $val) {
        if(property_exists($val, 'message')) {
          $created_at = $val->created_time;
          $created_date_object = date_create($created_at);
          $formatted_date = date_format($created_date_object, $config->get('feed_parameters.date_format'));
          $image = "";
          if(property_exists($val, 'attachments') && property_exists($val->attachments->data[0], 'media')) $image = "<img class='media-object' src='".$val->attachments->data[0]->media->image->src."' alt='kuva'>";
          $r[$created_at]['time'] = $formatted_date;
          $r[$created_at]['image'] = $image;
          $r[$created_at]['message'] = nl2br($val->message);
          $r[$created_at]['link'] = $val->permalink_url;
        }
      }
      $this->addFeed($r);
    }

    private function addFeed($result) {
      foreach ($result as $key => $val) {
        if (!isset($this->feeds[$key])) {
          $this->feeds[$key] = $val;
        }
      }
      krsort($this->feeds);
    }
}
