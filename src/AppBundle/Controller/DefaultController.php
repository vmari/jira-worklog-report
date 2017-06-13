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
        dump($project);
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

        $jql = 'project = ' . $project . ' and created <= "' .
            $to->format('Y-m-d') . '" and updated >= "' .
            $from->format('Y-m-d') . '" and timespent > 0';

        $error = null;
        $worklogs = null;
        $teamTotals = array();
        $totalSeconds = 0;

        try {
            $conf = new ArrayConfiguration(array(
                'jiraHost' => $server->getBaseUrl(),
                'jiraUser' => $server->getUsername(),
                'jiraPassword' => $server->getPasswd()
            ));
            $iss = new IssueService($conf);
            $issues = $iss->search($jql, 0, 1000, ['summary', 'worklog']);
            dump($issues);
            $worklogs = array();
            $teamLogs = array();
            foreach ($issues->issues as $issue) {
                if ($issue->fields->worklog) {
                    foreach ($issue->fields->worklog->worklogs as $worklog) {
                        $worklog->created = new \DateTime($worklog->created);
                        $worklog->updated = new \DateTime($worklog->updated);
                        $worklog->started = new \DateTime($worklog->started);
                        $worklog->issue = $issue;

                        if ($worklog->started < $to && $worklog->started > $from) {
                            $worklogs[] = $worklog;
                            $teamLogs[$worklog->author->key][] = $worklog;
                            $totalSeconds += $worklog->timeSpentSeconds;
                        }
                    }
                }
            }

            usort($worklogs, function ($a, $b) {
                if ($a->started == $b->started) {
                    return 0;
                }
                return ($a->started < $b->started) ? -1 : 1;
            });

            foreach ($teamLogs as $p => $logs) {
                $total = 0;
                foreach ($logs as $log) {
                    $total += $log->timeSpentSeconds;
                }
                $teamTotals[$p] = $total;
            }

        } catch (JiraException $e) {
            $this->addFlash('danger', 'Error, maybe project not exists.');
            return $this->redirectToRoute('index');
        }

        return $this->render('AppBundle:Default:worklog.html.twig', array(
            'error' => $error,
            'data' => $worklogs,
            'teamTotals' => $teamTotals,
            'total' => $totalSeconds,
            'from' => $from,
            'to' => $to,
            'server' => $server,
            'project' => $project
        ));
    }
}
