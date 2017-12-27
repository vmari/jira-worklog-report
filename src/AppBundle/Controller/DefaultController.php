<?php

namespace AppBundle\Controller;

use AppBundle\Entity\JiraServer;
use AppBundle\Form\JiraServerType;
use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\JiraException;
use JiraRestApi\Project\ProjectService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use JiraRestApi\JiraClient;

class DefaultController extends Controller
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @Route("/", name="index")
     */
    public function indexAction(Request $request)
    {
        $request->getSession()->remove('server');
        $js = new JiraServer();
        $form = $this->createForm(JiraServerType::class, $js);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $js->setBaseUrl('https://' . parse_url($js->getBaseUrl(), PHP_URL_HOST));
            $request->getSession()->set('server', $js);
            return $this->redirectToRoute('projects');
        }

        return $this->render('AppBundle:Default:index.html.twig', array('form' => $form->createView()));
    }

    /**
     * @Route("/projects", name="projects")
     */
    public function projectsAction(Request $request)
    {
        /** @var JiraServer $server */
        $server = $request->getSession()->get('server');

        if (!$server) {
            return $this->redirectToRoute('index');
        }

        try {
            $conf = new ArrayConfiguration(array(
                'jiraHost' => $server->getBaseUrl(),
                'jiraUser' => $server->getUsername(),
                'jiraPassword' => $server->getPasswd()
            ));
            $proj = new ProjectService($conf);
            $projects = $proj->getAllProjects();
        } catch (JiraException $e) {
            $this->addFlash('danger', 'Bad credentials.');
            return $this->redirectToRoute('index');
        }

        return $this->render('AppBundle:Default:projects.html.twig', array(
            'projects' => $projects,
            'server' => $server
        ));
    }

    /**
     * @Route("/{project}/worklog/{from}/{to}", name="worklog")
     *
     * @ParamConverter("from", options={"format": "Y-m-d"})
     * @ParamConverter("to", options={"format": "Y-m-d"})
     */
    public function worklogAction(Request $request, $project, \DateTime $from = null, \DateTime $to = null)
    {
        if (!$from) {
            /** @var \DateTime $from */
            $from = new \DateTime('now');
        }

        if (!$to) {
            /** @var \DateTime $to */
            $to = New \DateTime('now');
        }

        $from->setTime(0, 0);
        $to->setTime(23, 59, 59);

        /** @var JiraServer $server */
        $server = $request->getSession()->get('server');

        if (!$server) {
            return $this->redirectToRoute('index');
        }

        try {
            $issues = $this->getIssues($server, $project, $from, $to);
            $data = $this->parseIssues($server, $issues, $from, $to);
            $sprints = $this->getSprints($server, $project);
        } catch (JiraException $e) {
            $this->addFlash('danger', 'Error, maybe project not exists.');
            return $this->redirectToRoute('index');
        }

        return $this->render('AppBundle:Default:worklog.html.twig', array(
            'data' => $data["worklogs"],
            'teamTotals' => $data["teamTotals"],
            'total' => $data["totalSeconds"],
            'from' => $from,
            'to' => $to,
            'server' => $server,
            'project' => $project,
            'sprints' => $sprints
        ));
    }

    /**
     * @Route("/{project}/worklog/{from}/{to}/export", name="export")
     *
     * @ParamConverter("from", options={"format": "Y-m-d"})
     * @ParamConverter("to", options={"format": "Y-m-d"})
     */
    public function exportAction(Request $request, $project, \DateTime $from = null, \DateTime $to = null)
    {
        if (!$from) {
            /** @var \DateTime $from */
            $from = new \DateTime('now');
        }

        if (!$to) {
            /** @var \DateTime $to */
            $to = new \DateTime('now');
        }

        $from->setTime(0, 0);
        $to->setTime(23, 59, 59);

        /** @var JiraServer $server */
        $server = $request->getSession()->get('server');
        $fp = fopen('php://memory', 'w');

        if (!$server) {
            return $this->redirectToRoute('index');
        }

        try {
            $issues = $this->getIssues($server, $project, $from, $to);
            $data = $this->parseIssues($server, $issues, $from, $to);
        } catch (JiraException $e) {
            $this->addFlash('danger', 'Error, maybe project not exists.');
            return $this->redirectToRoute('index');
        }

        // Get the users and group their worklogs
        $userWorklogs = array();
        foreach ($data["worklogs"] as $worklog) {
            $userName = $worklog->author->key;
            if (!in_array($userName, array_keys($userWorklogs))) {
                $userWorklogs[$userName] = array();
            }
            $userWorklog = array(
                '=HYPERLINK("' . $this->getWorklogLink($server, $worklog) . '";"' . $worklog->id . '")',
                '=HYPERLINK("' . $this->getIssueLink($server, $worklog->issue->key) . '";"' . $worklog->issue->key . '")',
                number_format(floatval($worklog->timeSpentSeconds) / 60 / 60, 2, ',', ''),
                date_format($worklog->started, 'Y-m-d H:i'),
                " "
            );
            array_push($userWorklogs[$userName], $userWorklog);
        }

        // Get the total time of each user
        foreach (array_keys($userWorklogs) as $key) {
            array_push($userWorklogs[$key], array("", "", "Total: " . number_format($data["teamTotals"][$key] / 60 / 60, 2) . "h", "", ""));
        }

        // Get name headers
        $namesHeaders = [];
        foreach (array_keys($userWorklogs) as $key) {
            array_push($namesHeaders, "", $key, "", "", "");
        }

        // Get the worklog headers
        $worklogHeaders = [];
        foreach (array_keys($userWorklogs) as $key) {
            array_push($worklogHeaders, 'Worklog', 'Issue', 'Time Spent (h)', 'Started', " ");
        }

        // Put the headers
        fputcsv($fp, $namesHeaders);
        fputcsv($fp, $worklogHeaders);

        // Get the max amount of worklogs (number of rows)
        $maxUserWorklogs = -1;
        foreach (array_keys($userWorklogs) as $key) {
            if (sizeof($userWorklogs[$key]) > $maxUserWorklogs) {
                $maxUserWorklogs = sizeof($userWorklogs[$key]);
            }
        }

        // Merge user's worklogs to create the rows
        $rowWorklogs = array();
        for ($i = 0; $i < $maxUserWorklogs; $i++) {
            $rowWorklogs[$i] = array();
            foreach (array_keys($userWorklogs) as $key) {
                $worklog = array_shift($userWorklogs[$key]);
                if (is_null($worklog)) {
                    $worklog = array("", "", "", "", "");
                }
                $rowWorklogs[$i] = array_merge($rowWorklogs[$i], $worklog);
            }
        }

        // Put the worklogs (rows)
        for ($i = 0; $i < $maxUserWorklogs; $i++) {
            fputcsv($fp, $rowWorklogs[$i]);
        }

        fseek($fp, 0);

        $csv = stream_get_contents($fp);

        fclose($fp);
        $csv = mb_convert_encoding($csv, "UTF-8");

        $response = new Response($csv);
        $filename = $from->format('d-m-Y') . '-' . $to->format('d-m-Y') . '.csv';
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s";', $filename));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        return $response;

    }

    private function getWorklogQuery($project, \DateTime $from, \DateTime $to)
    {
        return 'project = ' . $project . ' and created <= "' .
            $to->add(new \DateInterval("P5D"))->format('Y-m-d') . '" and updated >= "' .
            $from->format('Y-m-d') . '" and timespent > 0';
    }

    private function getServerConfig($server)
    {
        return new ArrayConfiguration(array(
            'jiraHost' => $server->getBaseUrl(),
            'jiraUser' => $server->getUsername(),
            'jiraPassword' => $server->getPasswd()
        ));
    }

    private function parseIssues($server, $issues, $from, $to)
    {
        $parsedData = array(
            "teamTotals" => array(),
            "totalSeconds" => 0,
            "worklogs" => array(),
            "teamLogs" => array()
        );

        $service = new IssueService($this->getServerConfig($server));

        foreach ($issues->issues as $issue) {
            if ($issue->fields->worklog) {

                if ($issue->fields->worklog->maxResults <= $issue->fields->worklog->total) {
                    $issue->fields->worklog = $service->getWorklog($issue->key);
                }

                foreach ($issue->fields->worklog->worklogs as $worklog) {
                    $worklog = json_decode(json_encode($worklog, false));
                    $worklog->created = new \DateTime($worklog->created);
                    $worklog->updated = new \DateTime($worklog->updated);
                    $worklog->started = new \DateTime($worklog->started);
                    $worklog->issue = $issue;

                    if ($worklog->started < $to && $worklog->started > $from) {
                        $parsedData["worklogs"][] = $worklog;
                        $parsedData["teamLogs"][$worklog->author->key][] = $worklog;
                        $parsedData["totalSeconds"] += $worklog->timeSpentSeconds;
                    }
                }
            }
        }

        usort($parsedData["worklogs"], function ($a, $b) {
            if ($a->started == $b->started) {
                return 0;
            }
            return ($a->started < $b->started) ? -1 : 1;
        });

        foreach ($parsedData["teamLogs"] as $p => $logs) {
            $total = 0;
            foreach ($logs as $log) {
                $total += $log->timeSpentSeconds;
            }
            $parsedData["teamTotals"][$p] = $total;
        }

        return $parsedData;
    }

    private function getIssues($server, $project, $from, $to)
    {
        $service = new IssueService($this->getServerConfig($server));
        return $service->search($this->getWorklogQuery($project, $from, $to), 0, 1000, ['summary', 'worklog']);
    }

    private function getWorklogLink($server, $worklog)
    {
        return $server->getBaseUrl() . '/browse/' . $worklog->issue->key . '?focusedWorklogId=' . $worklog->id .
            '&page=com.atlassian.jira.plugin.system.issuetabpanels%3Aworklog-tabpanel#worklog-' . $worklog->id;
    }

    private function getIssueLink($server, $issueKey)
    {
        return $server->getBaseUrl() . '/browse/' . $issueKey;
    }

    /**
     * Get a board using the project short identification (e.g: "SYN" or "DOT")
     *
     * @param object $server The server configuration
     * @param string $projectKeyName The project short identification (e.g: "SYN" or "DOT)
     *
     * @return object - Board information
     */
    private function getBoard($server, $projectKeyName)
    {
        if (!$server) {
            return $this->redirectToRoute('index');
        }
        $jc = new JiraClient($this->getServerConfig($server));

        $jc->setAPIUri('/rest/agile/1.0');
        $resp = $jc->exec('/board?projectKeyOrId=' . $projectKeyName);
        $board = json_decode($resp);

        return $board;
    }

    /**
     * Get the sprints for a particular board.
     *
     * @param object $server The server configuration
     * @param string $projectKeyName The project short identification (e.g: "SYN" or "DOT)
     *
     * @return associative array - Sprints information ordered by descending endDate.
     */
    private function getSprints($server, $projectKeyName)
    {
        if (!$server) {
            return $this->redirectToRoute('index');
        }

        $board = $this->getBoard($server, $projectKeyName);

        $jc = new JiraClient($this->getServerConfig($server));

        $jc->setAPIUri('/rest/agile/1.0');

        $resp = $jc->exec('/board/' . $board->values[0]->id . '/sprint');

        $response = json_decode($resp, true);

        $sprints = $response['values'];

        foreach ($sprints as $sprint) {
            $sprint['endDate'] = new \DateTime($sprint['endDate']);
        }

        usort($sprints, function ($sprint1, $sprint2) {
            if ($sprint1['endDate'] == $sprint2['endDate'])
                return 0;

            return ($sprint1['endDate'] < $sprint2['endDate']) ? 1 : -1;
        });

        if (count($sprints) >= 5) {
            $sprints = array_slice($sprints, 0, 5);
        }

        return $sprints;
    }
}
