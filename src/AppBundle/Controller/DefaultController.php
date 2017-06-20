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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class DefaultController extends Controller
{
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

            $js->setBaseUrl('https://'.parse_url($js->getBaseUrl(), PHP_URL_HOST));
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
     * @Route("/{project}/worklog/{from}/{to}/export", name="export")
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

        $jql = $this->getWorklogQuery($project, $from, $to);

        try {
            $issues = $this->getIssues($server, $project, $from, $to);
            $data   = $this->parseIssues($issues, $from, $to);
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
            'project' => $project
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
        $filename = 'report.csv';
        $fp = fopen($filename, 'w+');

        if (!$server) {
            return $this->redirectToRoute('index');
        }

        $jql = $this->getWorklogQuery($project, $from, $to);

        try {
            $issues = $this->getIssues($server, $project, $from, $to);
            $data   = $this->parseIssues($issues, $from, $to);
        } catch (JiraException $e) {
            $this->addFlash('danger', 'Error, maybe project not exists.');
            return $this->redirectToRoute('index');
        }
        
        // Put the headers
        fputcsv($fp, array('Worklog', 'Issue', 'Author', 'Time Spent', 'Started'));

        foreach($data["worklogs"] as $worklog) {
            fputcsv($fp, array(
                '=HYPERLINK("' . $this->getWorklogLink($server, $worklog) . '","' . $worklog->id . '")',
                '=HYPERLINK("'. $this->getIssueLink($server, $worklog->issue->key) . '","' . $worklog->issue->key . '")',
                $worklog->author->displayName,
                $worklog->timeSpent,
                date_format($worklog->started, 'Y-m-d')
            ));
        }

        fclose($fp);
        
        $response = new BinaryFileResponse($filename);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        return $response;
     }

     private function getWorklogQuery($project, \DateTime $from, \DateTime $to)
     {
         return 'project = ' . $project . ' and created <= "' .
            $to->format('Y-m-d') . '" and updated >= "' .
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

     private function parseIssues($issues, $from, $to)
     {
         $parsedData = array(
            "teamTotals"   => array(),
            "totalSeconds" => 0,
            "worklogs"     => array(),
            "teamLogs"     => array()
         );
         
         foreach ($issues->issues as $issue) {
                if ($issue->fields->worklog) {
                    foreach ($issue->fields->worklog->worklogs as $worklog) {
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
}
