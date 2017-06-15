<?php

namespace Grav\Plugin\Console;

use Grav\Common\Data\Data;
use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\Response;
use Grav\Common\Grav;
use Grav\Common\Inflector;
use Grav\Common\Twig\Twig;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Facebook\FacebookEvents;
use RocketTheme\Toolbox\File\File;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

class FetchEventsCommand extends ConsoleCommand
{
    /**
     * @var Inflector
     */
    protected $inflector;

    /**
     * @var Locator
     */
    protected $locator;

    /**
     * @var Twig
     */
    protected $twig;


    /**
     * Initializes the basic requirements and Grav instance
     * inspired by
     * https://github.com/getgrav/grav-plugin-devtools/blob/develop/classes/DevToolsCommand.php
     */
    protected function init()
    {
        $grav = Grav::instance();
        $grav['config']->init();
        $grav['uri']->init();

        $this->locator = $grav['locator'];
        $this->twig = $grav['twig'];

        //Add `theme://` to prevent fail
        $this->locator->addPath('theme', '', []);
    }


    protected $options = [];

    private $template_page = 'facebook.event.page.md.twig';

    protected function configure()
    {
        $this
            ->setName("fetch-events")
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'The path for storing the generated pages, each event will be in a folder with the event id.'
            )
            ->addOption(
                'page-id',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Override page_id used for fetching events.'
            )
            ->setDescription("fetches events from facebook page")
            ->setHelp('fetches events from facebook page, so they can be used as child pages.');
    }


    protected function serve()
    {
        $this->init();

        $path = $this->input->getArgument('path');
        // todo: sanitize path to end with '/'
        if (!$path) {
            $path = 'user/pages/events/';
        }

        $grav = Grav::instance();
        $pluginConfig = New Data($grav['config']->get('plugins.facebook'));
        $events = new FacebookEvents($pluginConfig);

        if ($this->input->hasOption('page-id')) {
            $events->setEventPageId($this->input->getOption('page-id'));
        }

        $date_format = $grav['config']->get('system.pages.dateformat.default');
        $this->output->writeln('date format:' . $date_format);

        $url = $events->getGraphUrl('fields=id,start_time,end_time,name,description,place,updated_time');
        $fetched = (new \DateTime())->format('c');
        $list = $events->parseEvents(Response::get($url), 'id');
        $this->output->writeln('fetched:' . $fetched);

        $this->output->writeln('path:' . $path);
        $this->output->writeln('page-id:' . $events->getEventPageId());
        if (count($list) > 0) {
            Folder::create($path);

            $this->output->writeln('events:');
            $templateFolder = __DIR__ . '/../templates/';
            $this->twig->twig_paths[] = $templateFolder;
            $this->twig->init();

            foreach ($list as $event_id => $event) {
                $date = $this->formatDate($event, 'original_start', $date_format);
                $date_y_m_d = $date->format('Y-m-d');
                $event_folder = $path . DS . 'fb-event-' . $date_y_m_d . '-' . $event_id;
                Folder::create($event_folder);

                $event['menu'] = $date_y_m_d . ' ' . $event['name'];
                $event['fb_cover'] = Yaml::dump(['fb_cover' => $event['cover']], 3);
                $event['fb_place'] = Yaml::dump(['fb_place' => $event['place']], 3);
                $event['fetched_time'] = $fetched;

                $date_end = $this->formatDate($event, 'original_end', $date_format);
                $event['unpublish_date'] = $date_end->add(new \DateInterval('P1D'))->format($date_format);

                $page = $this->twig->processTemplate($this->template_page, $event);
                $page_file = $event_folder . DS . 'default.md';
                $file = File::instance($page_file); //TODO option for template name
                $file->content($page);
                $file->save();
                $this->output->writeln("Successfully created or updated $page_file");

            }
        }
    }

    private function formatDate(&$dict, $key, $output_format, $input_format = 'Y-m-d\TH:i:sT')
    {
        $date = \DateTime::createFromFormat($input_format, $dict[$key]);
        if ($date === false) {
            dump(\DateTime::getLastErrors());
            throw new RuntimeException('could not create date from "' . $dict[$key] . '" found in field "' . $key . '".');
        }
        $dict[$key] = $date->format($output_format);
        return $date;
    }

}