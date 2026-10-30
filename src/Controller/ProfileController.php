<?php

namespace GlpiPlugin\Azuredevops\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Azuredevops\Link;
use Profile;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    #[Route('/Profile/{profiles_id}/Rights', name: 'azuredevops_profile_rights', requirements: ['profiles_id' => '\d+'], methods: ['POST'])]
    public function save(Request $request, int $profiles_id): Response
    {
        // Same permission as editing the profile in GLPI itself.
        if (!Session::haveRight('profile', UPDATE)) {
            throw new AccessDeniedHttpException();
        }
        $profile = new Profile();
        if (!$profile->getFromDB($profiles_id)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }

        Link::saveProfileRights($profiles_id, (array) $request->request->all('rights'));
        Session::addMessageAfterRedirect(__('Rights updated.', 'azuredevops'));

        return new RedirectResponse($request->headers->get('referer') ?: Profile::getFormURLWithID($profiles_id));
    }
}
