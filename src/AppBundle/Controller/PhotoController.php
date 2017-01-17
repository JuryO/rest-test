<?php
/**
 * Created by PhpStorm.
 * User: Jury
 * Date: 16-Jan-17
 * Time: 21:17
 */

namespace AppBundle\Controller;


use AppBundle\Entity\Photo;
use AppBundle\Entity\Tag;
use AppBundle\Entity\TagRel;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use JMS\Serializer\SerializerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PhotoController extends FOSRestController
{
    /**
     * Search photos by tag
     * Route: /photos/search
     * Method: GET
     * Parameters: tag
     *
     * @Rest\Get("/photos/search")
     */
    public function getPhotosByTagAction(Request $request)
    {
        $tag = $request->get('tag');
        $em = $this->getDoctrine()->getEntityManager();

        // check if tag exists in db
        $dbTag = $em->getRepository('AppBundle:Tag')->findOneByName(trim($tag));
        if (!$dbTag) {
            //show error if tag not exists
            $data = ['status' => 'error', 'message' => 'There is no such tag'];
            $view = $this->view($data, Response::HTTP_INTERNAL_SERVER_ERROR);
            return $view;
        }

        // get a list of photos
        $photos = $em
            ->createQueryBuilder()
            ->select('p.id, p.image')
            ->from('AppBundle:TagRel' , 'tr')
            ->leftJoin('tr.tag', 't')
            ->leftJoin('tr.photo', 'p')
            ->where('t.id = (:tagId)')
            ->setParameter('tagId', $dbTag->getId())
            ->getQuery()
            ->getResult();

        //serialize and show results
        $serializer = SerializerBuilder::create()->build();
        $data = ['status' => 'success', 'photos' => $serializer->serialize($photos, 'json')];
        $view = $this->view($data, Response::HTTP_OK);
        return $view;
    }

    /**
     * Get list of photos with tags
     * Route: /photos/{page}
     * Method: GET
     *
     * @Rest\Get("/photos{trailingSlash}{page}", requirements={"trailingSlash" = "[/]{0,1}"}, defaults={"trailingSlash" = "/", "page" = 1})
     */
    public function getPhotosAction(Request $request)
    {
        $page = $request->get('page');
        $perPage = $this->getParameter('photos_per_page');
        $em = $this->getDoctrine()->getEntityManager();

        // get a list of photos
        $photos = $em->getRepository('AppBundle:Photo')->findBy(array(), array(), $perPage, ($page - 1) * $perPage);

        //serialize and show results
        $serializer = SerializerBuilder::create()->build();
        $data = ['status' => 'success', 'photos' => $serializer->serialize($photos, 'json')];
        $view = $this->view($data, Response::HTTP_OK);
        return $view;
    }

    /**
     * Add new photo: HTTP POST request with jpg image in 'file' parameter
     * Route: /photos
     * Method: POST
     * Parameter: file
     *
     * @Rest\Post("/photos")
     */
    public function postPhotosAction(Request $request)
    {
        $em = $this->getDoctrine()->getEntityManager();

        $photo = new Photo();
        $file = $request->files->get('file');
        $fileName = $this->get('app.photo_uploader')->upload($file);

        $photo->setImage($fileName);
        $em->persist($photo);
        $em->flush();
        $data = ['status' => 'success', 'id' => $photo->getId()];
        $view = $this->view($data, Response::HTTP_OK);
        return $view;
    }

    /**
     * Add tags to photo
     * Route: /photos/{id}/tags
     * Method: Post
     *
     * @Rest\Post("/photos/{photoId}/tags")
     */
    public function postTagsAction(Request $request)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $photoId = $request->get('photoId');
        $photo = false;
        if ($photoId) {
            $photo = $em->getRepository('AppBundle:Photo')->findOneById($photoId);
        }
        // check if photo exists in DB
        if (!$photo) {
            $data = ['status' => 'error', 'message' => 'There is no photo with such ID'];
            $view = $this->view($data, Response::HTTP_INTERNAL_SERVER_ERROR);
            return $view;
        }

        $tags = explode(',', $request->get('tags'));
        foreach ($tags as $tag) {
            if (trim($tag) != '') {
                // check if tag already exists, create new one if no
                $dbTag = $em->getRepository('AppBundle:Tag')->findOneByName(trim($tag));
                if (!$dbTag) {
                    $dbTag = new Tag();
                }

                $dbTag->setName(trim($tag));
                $em->persist($dbTag);
                $em->flush();

                // set tag relations with a photo
                $tagRel = new TagRel();
                $tagRel->setPhoto($photo);
                $tagRel->setTag($dbTag);

                $em->persist($tagRel);
                $em->flush();
            }
        }
        $data = ['status' => 'success'];
        $view = $this->view($data, Response::HTTP_OK);
        return $view;
    }

    /**
     * Delete Tags
     * Route: /photos/id/tags
     * Method: DELETE
     *
     * @Rest\Delete("/photos/{photoId}/tags")
     */
    public function deleteTagsAction(Request $request)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $photoId = $request->get('photoId');
        $photo = false;
        if ($photoId) {
            $photo = $em->getRepository('AppBundle:Photo')->findOneById($photoId);
        }
        //check if photo exists in DB
        if (!$photo) {
            $data = ['status' => 'error', 'message' => 'There is no photo with such ID'];
            $view = $this->view($data, Response::HTTP_INTERNAL_SERVER_ERROR);
            return $view;
        }

        //remove each tag
        $tags = explode(',', $request->get('tags'));
        foreach ($tags as $tag) {
            if (trim($tag) != '') {
                $dbTag = $em->getRepository('AppBundle:Tag')->findOneByName(trim($tag));
                if ($dbTag) {
                    $dbTagRel = $em->getRepository('AppBundle:TagRel')->findOneBy(array('photo' => $photo, 'tag' => $dbTag));
                    $em->remove($dbTagRel);
                    $em->flush();
                }
            }
        }
        $data = ['status' => 'success'];
        $view = $this->view($data, Response::HTTP_OK);
        return $view;
    }

    /**
     * Create new or update photo
     * Route: /photos/{id}
     * Method: PUT
     * Parameters: file
     *
     * @Rest\Put("/photos/{photoId}")
     */
    public function putPhotoByIdAction(Request $request)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $photoId = $request->get('photoId');
        $photo = false;
        if ($photoId) {
            $photo = $em->getRepository('AppBundle:Photo')->findOneById($photoId);
//            if (!$photo) {
//                $data = ['status' => 'error', 'message' => 'There is no photo with such ID'];
//                $view = $this->view($data, Response::HTTP_INTERNAL_SERVER_ERROR);
//                return $view;
//            }
        }
        if (!$photo) {
            $photo = new Photo();
        }

        $file = $request->files->get('file');
        $fileName = $this->get('app.photo_uploader')->upload($file);

        $photo->setImage($fileName);
        $em->persist($photo);
        $em->flush();

        $data = ['status' => 'success', 'id' => $photo->getId()];
        $view = $this->view($data, Response::HTTP_OK);
        return $view;
    }

    /**
     * Delete Photo
     * Route: /photo/{id}
     * Method: DELETE
     *
     * @Rest\Delete("/photo/{photoId}")
     */
    public function deletePhotoByIdAction(Request $request)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $photoId = $request->get('photoId');
        $photo = false;
        if ($photoId) {
            $photo = $em->getRepository('AppBundle:Photo')->findOneById($photoId);
        }
        //check if photo exists in BD
        if ($photo) {
            $em->remove($photo);
            $em->flush();
            $data = ['status' => 'success'];
            $view = $this->view($data, Response::HTTP_OK);
            return $view;
        } else {
            $data = ['status' => 'error', 'message' => 'There is no photo with such ID'];
            $view = $this->view($data, Response::HTTP_INTERNAL_SERVER_ERROR);
            return $view;
        }
    }

}