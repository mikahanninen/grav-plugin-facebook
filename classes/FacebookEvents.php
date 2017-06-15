<?php

namespace Grav\Plugin\Facebook;

use Grav\Common\Data\Data;

class FacebookEvents
{
    private $eventsPageId;
    private $config;


    /**
     * FacebookEvents constructor.
     * @param $config Data
     */
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function getEventPageId()
    {
        if (empty($this->eventsPageId)) {
            $this->eventsPageId = empty($this->config->get('facebook_event_settings.events_page_id'))
                ? $this->config->get('facebook_page_settings.page_id')
                : $this->config->get('facebook_event_settings.events_page_id');
        }
        return $this->eventsPageId;
    }

    public function setEventPageId($id)
    {
        $this->eventsPageId = $id;
    }

    public function getAccessToken()
    {
        $id = $this->config->get('facebook_common_settings.application_id');
        $secret = $this->config->get('facebook_common_settings.application_secret');
        if (empty($id) || empty($secret)) {
            throw new \RuntimeException("expected configuration for application_id and application_secret to not be empty.");
        }
        return $id . '|' . $secret;
    }

    public function getGraphUrl($parameters)
    {
        return 'https://graph.facebook.com/' . $this->getEventPageId() . '/events?'
            . $parameters . '&access_token=' . $this->getAccessToken();
    }

    public function parseEvents($json_str, $index_field)
    {
        $r = array();
        $content = json_decode($json_str);

        foreach ($content->data as $event) {
            $enrichedEvent = $this->enrichEvent($event);
            if (array_key_exists($index_field, $enrichedEvent)) {
                $r[$enrichedEvent[$index_field]] = $enrichedEvent;
            } else if (array_key_exists($index_field, $event)) {
                $r[$event->{$index_field}] = $enrichedEvent;
            } else {
                print 'could not index the following data, because index_field was not found:\n';
                dump($event);
            }
        }
        return $r;
    }

    public function enrichEvent($event)
    {
        $r = array();
        if (property_exists($event, 'start_time') && property_exists($event, 'end_time')) {
            $start_at = $event->start_time;
            $end_at = $event->end_time;
            $start_date_array = date_parse($start_at);
            $end_date_array = date_parse($end_at);

            $start_date_array['monthName'] =
                date('F', mktime(0, 0, 0, $start_date_array['month'], 10));
            $start_date_array['dayName'] =
                date('l', mktime(0, 0, 0, $start_date_array['month'], $start_date_array['day'],
                    $start_date_array['year']));
            $end_date_array['monthName'] =
                date('F', mktime(0, 0, 0, $end_date_array['month'], 10));

            $r['original_start'] = $start_at;
            $r['original_end'] = $end_at;
            $start_date_array['hour'] =
                ($start_date_array['hour'] == 0) ? '00' : $start_date_array['hour'];
            $start_date_array['minute'] =
                ($start_date_array['minute'] == 0) ? '00' : $start_date_array['minute'];
            $end_date_array['hour'] =
                ($end_date_array['hour'] == 0) ? '00' : $end_date_array['hour'];
            $end_date_array['minute'] =
                ($end_date_array['minute'] == 0) ? '00' : $end_date_array['minute'];
            $r['start_time'] = $start_date_array;
            $r['end_time'] = $end_date_array;
            if ($this->isStartDaySameAsEnd($start_date_array, $end_date_array)) {
                $r['period'] =
                    $start_date_array['dayName'] . ' ' . $start_date_array['hour'] . ':'
                    . $start_date_array['minute'];
            } else {
                $r['period'] =
                    $start_date_array['day'] . '. ' . $start_date_array['monthName'] . ' '
                    . $start_date_array['year'] . ' - ' . $end_date_array['day'] . '. '
                    . $end_date_array['monthName'] . ' ' . $end_date_array['year'];
            }
        }
        $r['event_link'] = $event->id;
        $r['name'] = nl2br($event->name);
        $r['place'] = '';
        $r['description'] = '';
        $r['cover'] = '';
        $r['updated_time'] = '';

        if (property_exists($event, 'cover')) {
            $r['cover'] = (array)$event->cover;
        }
        if (property_exists($event, 'place')) {
            $r['place'] = (array)$event->place;
            if (property_exists($event->place, 'location')) {
                $r['place']['location'] = (array)$event->place->location;
            }

        }
        if (property_exists($event, 'description')) {
            $r['description'] = $event->description;
        }
        if (property_exists($event, 'updated_time')) {
            $r['updated_time'] = $event->updated_time;
        }
        //todo: what about more fields?

        return $r;
    }

    private function isStartDaySameAsEnd($start, $end)
    {
        return (($start['year'] == $end['year']) && ($start['month'] == $end['month'])
            && ($start['day'] == $end['day']));
    }

}