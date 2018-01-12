<?php

namespace AppBundle\Services;

use AppBundle\Entity\JiraServer;
use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\JiraClient;
use JiraRestApi\JiraException;
use JiraRestApi\Project\ProjectService;

class WorklogService {

  private function getServerConfig( JiraServer $server ) {
    return new ArrayConfiguration( array(
      'jiraHost'     => $server->getBaseUrl(),
      'jiraUser'     => $server->getUsername(),
      'jiraPassword' => $server->getPasswd()
    ) );
  }

  /**
   * Get the sprints for a particular board.
   *
   * @param JiraServer $server The server configuration
   * @param string $projectKeyName The project short identification (e.g: "SYN" or "DOT)
   *
   * @return array - Sprints information ordered by descending endDate.
   */
  public function getSprints( $server, $projectKeyName ) {
    $jc = new JiraClient( $this->getServerConfig( $server ) );
    $jc->setAPIUri( '/rest/agile/1.0' );

    $resp  = $jc->exec( '/board?projectKeyOrId=' . $projectKeyName );
    $board = json_decode( $resp );

    $resp     = $jc->exec( '/board/' . $board->values[0]->id . '/sprint' );
    $response = json_decode( $resp );
    $sprints  = $response->values;

    foreach ( $sprints as $sprint ) {
      $sprint->startDate = new \DateTime( $sprint->startDate );
      $sprint->endDate   = new \DateTime( $sprint->endDate );
    }

    usort( $sprints, function ( $sprint1, $sprint2 ) {
      if ( $sprint1->endDate == $sprint2->endDate ) {
        return 0;
      }

      return ( $sprint1->endDate < $sprint2->endDate ) ? 1 : - 1;
    } );

    return $sprints;
  }

  public function getSprint( $server, $sprintID ) {
    $jc = new JiraClient( $this->getServerConfig( $server ) );
    $jc->setAPIUri( '/rest/agile/1.0' );
    $resp              = $jc->exec( '/sprint/' . $sprintID );
    $sprint            = json_decode( $resp );
    $sprint->startDate = new \DateTime( $sprint->startDate );
    $sprint->endDate   = new \DateTime( $sprint->endDate );

    return $sprint;
  }

  /**
   * @param JiraServer $server The server configuration
   *
   * @return array - Projects information ordered by descending endDate.
   */
  public function getProjects( $server ) {
    $proj     = new ProjectService( $this->getServerConfig( $server ) );
    $projects = $proj->getAllProjects();

    return $projects;
  }

  public function getWorklogs( JiraServer $server, $project, \DateTime $from, \DateTime $to ) {
    try {
      $issues = $this->getIssues( $server, $project, $from, $to );
      $data   = $this->parseIssues( $server, $issues, $from, $to );
    } catch ( JiraException $e ) {
      throw new \Exception( "Error, maybe project not exists." );
    }

    return $data;
  }

  private function getWorklogQuery( $project, \DateTime $from, \DateTime $to ) {
    $tmp = clone $to;

    return 'project = ' . $project . ' and created <= "' .
           $tmp->add( new \DateInterval( "P5D" ) )->format( 'Y-m-d' ) . '" and updated >= "' .
           $from->format( 'Y-m-d' ) . '" and timespent > 0';
  }

  private function parseIssues( $server, $issues, $from, $to ) {
    $parsedData = array(
      "worklogs"     => array(),
      "teamLogs"     => array(),
      "teamTotals"   => array(),
      "totalSeconds" => 0,
    );

    $service = new IssueService( $this->getServerConfig( $server ) );

    foreach ( $issues->issues as $issue ) {
      if ( $issue->fields->worklog ) {

        if ( $issue->fields->worklog->maxResults <= $issue->fields->worklog->total ) {
          $issue->fields->worklog = $service->getWorklog( $issue->key );
        }

        foreach ( $issue->fields->worklog->worklogs as $worklog ) {
          $worklog          = json_decode( json_encode( $worklog, false ) );
          $worklog->created = new \DateTime( $worklog->created );
          $worklog->updated = new \DateTime( $worklog->updated );
          $worklog->started = new \DateTime( $worklog->started );
          $worklog->issue   = $issue;

          if ( $worklog->started < $to && $worklog->started > $from ) {
            $parsedData["worklogs"][]                          = $worklog;
            $parsedData["teamLogs"][ $worklog->author->key ][] = $worklog;
            $parsedData["totalSeconds"]                        += $worklog->timeSpentSeconds;
          }
        }
      }
    }

    usort( $parsedData["worklogs"], function ( $a, $b ) {
      if ( $a->started == $b->started ) {
        return 0;
      }

      return ( $a->started < $b->started ) ? - 1 : 1;
    } );

    foreach ( $parsedData["teamLogs"] as $p => $logs ) {
      $total = 0;
      foreach ( $logs as $log ) {
        $total += $log->timeSpentSeconds;
      }
      $parsedData["teamTotals"][ $p ] = $total;
    }

    return $parsedData;
  }

  private function getIssues( JiraServer $server, $project, $from, $to ) {
    $service = new IssueService( $this->getServerConfig( $server ) );

    return $service->search( $this->getWorklogQuery( $project, $from, $to ), 0, 1000, [ 'summary', 'worklog' ] );
  }

  private function getWorklogLink( JiraServer $server, $worklog ) {
    return $server->getBaseUrl() . '/browse/' . $worklog->issue->key . '?focusedWorklogId=' . $worklog->id .
           '&page=com.atlassian.jira.plugin.system.issuetabpanels%3Aworklog-tabpanel#worklog-' . $worklog->id;
  }

  private function getIssueLink( JiraServer $server, $issueKey ) {
    return $server->getBaseUrl() . '/browse/' . $issueKey;
  }

}