<?php

namespace AppBundle\Controller;

use AppBundle\Entity\JiraServer;
use AppBundle\Form\JiraServerType;
use AppBundle\Services\XLSExportService;
use JiraRestApi\JiraException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends Controller {

  /**
   * @Route("/", name="index")
   */
  public function indexAction( Request $request ) {
    $request->getSession()->remove( 'server' );
    $js   = new JiraServer();
    $form = $this->createForm( JiraServerType::class, $js );

    $form->handleRequest( $request );

    if ( $form->isSubmitted() && $form->isValid() ) {

      $js->setBaseUrl( 'https://' . parse_url( $js->getBaseUrl(), PHP_URL_HOST ) );
      $request->getSession()->set( 'server', $js );

      return $this->redirectToRoute( 'projects' );
    }

    return $this->render( 'AppBundle:Default:index.html.twig', array( 'form' => $form->createView() ) );
  }

  /**
   * @Route("/projects", name="projects")
   */
  public function projectsAction( Request $request ) {
    /** @var JiraServer $server */
    $server = $request->getSession()->get( 'server' );

    if ( ! $server ) {
      return $this->redirectToRoute( 'index' );
    }

    try {
      $ws       = $this->get( 'worklog' );
      $projects = $ws->getProjects( $server );
    } catch ( JiraException $e ) {
      $this->addFlash( 'danger', 'Bad credentials.' );

      return $this->redirectToRoute( 'index' );
    }

    return $this->render( 'AppBundle:Default:projects.html.twig', array(
      'projects' => $projects,
      'server'   => $server
    ) );
  }

  /**
   * @Route("/{project}/sprint/{id}", name="sprint")
   */
  public function sprintAction( Request $request, $project, $id ) {
    /** @var JiraServer $server */
    $server = $request->getSession()->get( 'server' );

    if ( ! $server ) {
      return $this->redirectToRoute( 'index' );
    }

    try {
      $ws     = $this->get( 'worklog' );
      $sprint = $ws->getSprint( $server, $id );
    } catch ( JiraException $e ) {
      $this->addFlash( 'danger', 'Error, maybe project or sprint not exists.' );

      return $this->redirectToRoute( 'index' );
    }

    return $this->forward( 'AppBundle:Default:worklog', array(
      'project' => $project,
      'from'    => $sprint->startDate->format( 'Y-m-d' ),
      'to'      => $sprint->endDate->format( 'Y-m-d' ),
      'sprint'  => $sprint
    ) );
  }

  /**
   * @Route("/{project}/worklog/{from}/{to}", name="worklog")
   *
   * @ParamConverter("from", options={"format": "Y-m-d"})
   * @ParamConverter("to", options={"format": "Y-m-d"})
   */
  public function worklogAction( Request $request, $project, \DateTime $from = null, \DateTime $to = null ) {
    if ( ! $from ) {
      /** @var \DateTime $from */
      $from = new \DateTime( 'now' );
    }

    if ( ! $to ) {
      /** @var \DateTime $to */
      $to = New \DateTime( 'now' );
    }

    $from->setTime( 0, 0 );
    $to->setTime( 23, 59, 59 );

    /** @var JiraServer $server */
    $server = $request->getSession()->get( 'server' );

    $ws = $this->get( 'worklog' );

    if ( ! $server ) {
      return $this->redirectToRoute( 'index' );
    }
    $format = $request->query->get( 'format' );

    try {
      $sprints = $ws->getSprints( $server, $project );
      $logs    = $ws->getWorklogs( $server, $project, $from, $to );
    } catch ( JiraException $e ) {
      $this->addFlash( 'danger', 'Error, maybe project not exists.' );

      return $this->redirectToRoute( 'index' );
    }

    $sprint = $request->get( 'sprint' );

    if ( ! $sprint ) {
      foreach ( $sprints as $s ) {
        if ( $s->startDate->format( 'd-m-Y' ) == $from->format( 'd-m-Y' ) and
             $s->endDate->format( 'd-m-Y' ) == $to->format( 'd-m-Y' ) ) {
          $sprint = $s;
          break;
        }
      }
    }

    $data = array(
      'from'    => $from,
      'to'      => $to,
      'server'  => $server,
      'project' => $project,
      'logs'    => $logs,
      'sprints' => $sprints,
      'sprint'  => $sprint
    );

    if ( $format == 'xls' ) {
      $exportService = $this->get( 'export_service' );

      $response = new Response( $exportService->getFile( $data, $format ) );
      $filename = 'export.xls';
      $response->headers->set( 'Content-Disposition', sprintf( 'attachment; filename="%s";', $filename ) );
      $response->headers->set( 'Content-Type', 'application/vnd.ms-excel; charset=utf-8' );

      return $response;
    } else if ( $format == 'csv' ) {
      $exportService = $this->get( 'export_service' );

      $response = new Response( $exportService->getFile( $data, $format ) );
      $filename = 'export.csv';
      $response->headers->set( 'Content-Disposition', sprintf( 'attachment; filename="%s";', $filename ) );
      $response->headers->set( 'Content-Type', 'text/csv; charset=utf-8' );

      return $response;
    } else if ( $format == 'pdf' ) {
      $exportService = $this->get( 'export_service' );

      $response = new Response( $exportService->getFile( $data, $format ) );
      $filename = 'export.pdf';
      $response->headers->set( 'Content-Disposition', sprintf( 'attachment; filename="%s";', $filename ) );
      $response->headers->set( 'Content-Type', 'application/pdf; charset=utf-8' );

      return $response;
    } else if ( $format == 'xlsx' ) {
      $exportService = $this->get( 'export_service' );

      $response = new Response( $exportService->getFile( $data, $format ) );
      $filename = 'export.xlsx';
      $response->headers->set( 'Content-Disposition', sprintf( 'attachment; filename="%s";', $filename ) );
      $response->headers->set( 'Content-Type', 'application/vnd.ms-excel; charset=utf-8' );

      return $response;
    } else {
      return $this->render( 'AppBundle:Default:worklog.html.twig', $data );
    }
  }
}
