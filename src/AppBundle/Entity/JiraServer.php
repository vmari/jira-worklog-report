<?php

namespace AppBundle\Entity;

use Symfony\Component\Validator\Constraints as Assert;

class JiraServer implements \Serializable
{
    /**
     * @var string
     * @Assert\Url(
     *    checkDNS = true
     * )
     */
    private $baseUrl;

    /**
     * @var string
     * @Assert\NotBlank()
     */
    private $username;

    /**
     * @var string
     * @Assert\NotBlank()
     */
    private $passwd;

    public function serialize()
    {
        return serialize([$this->baseUrl, $this->username, $this->passwd]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        list(
            $this->baseUrl,
            $this->username,
            $this->passwd
            ) = $data;
    }

    /**
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->username = $username;
    }

    /**
     * @param string $passwd
     */
    public function setPasswd($passwd)
    {
        $this->passwd = $passwd;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function getPasswd()
    {
        return $this->passwd;
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * @param string $baseUrl
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

}