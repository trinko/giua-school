<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2021 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2021
 */


namespace App\Security;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;
use App\Entity\Docente;
use App\Util\ConfigLoader;
use App\Util\LogHandler;


/**
 * EnrollAuthenticator - servizio usato per registrare un docente e abilitarlo all'autenticazione con token
 */
class EnrollAuthenticator extends AbstractGuardAuthenticator {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var RouterInterface $router Gestore delle URL
   */
  private $router;

  /**
   * @var EntityManagerInterface $em Gestore delle entità
   */
  private $em;

  /**
   * @var SessionInterface $session Gestore delle sessioni
   */
  private $session;

  /**
   * @var UserPasswordEncoderInterface $encoder Gestore della codifica delle password
   */
  private $encoder;

  /**
   * @var CsrfTokenManagerInterface $csrf Gestore dei token CRSF
   */
  private $csrf;

  /**
   * @var LoggerInterface $logger Gestore dei log su file
   */
  private $logger;

  /**
   * @var LogHandler $dblogger Gestore dei log su database
   */
  private $dblogger;

  /**
   * @var ConfigLoader $config Gestore della configurazione su database
   */
  private $config;


  //==================== METODI DELLA CLASSE ====================

  /**
   * Costruttore
   *
   * @param RouterInterface $router Gestore delle URL
   * @param EntityManagerInterface $em Gestore delle entità
   * @param SessionInterface $session Gestore delle sessioni
   * @param UserPasswordEncoderInterface $encoder Gestore della codifica delle password
   * @param CsrfTokenManagerInterface $csrf Gestore dei token CRSF
   * @param LoggerInterface $logger Gestore dei log su file
   * @param LogHandler $dblogger Gestore dei log su database
   * @param ConfigLoader $config Gestore della configurazione su database
   */
  public function __construct(RouterInterface $router, EntityManagerInterface $em, SessionInterface $session,
                               UserPasswordEncoderInterface $encoder, CsrfTokenManagerInterface $csrf,
                               LoggerInterface $logger, LogHandler $dblogger, ConfigLoader $config) {
    $this->router = $router;
    $this->em = $em;
    $this->session = $session;
    $this->encoder = $encoder;
    $this->csrf = $csrf;
    $this->logger = $logger;
    $this->dblogger = $dblogger;
    $this->config = $config;
  }

  /**
   * Restituisce una pagina che invita l'utente ad autenticarsi.
   * Il metodo è eseguito quando un utente anonimo accede a risorse che richiedono l'autenticazione.
   * Lo scopo del metodo è restituire una pagina che permetta all'utente di iniziare il processo di autenticazione.
   *
   * @param Request $request Pagina richiesta
   * @param AuthenticationException $authException Eccezione che inizia il processo di autenticazione
   *
   * @return Response Pagina di risposta
   */
  public function start(Request $request, AuthenticationException $authException = null) {
    // eccezione che ha richiesto l'autenticazione
    $exception = new CustomUserMessageAuthenticationException('exception.auth_required');
    $request->getSession()->set(Security::AUTHENTICATION_ERROR, $exception);
    // redirect alla pagina di login
    return new RedirectResponse($this->router->generate('login_form'));
  }

  /**
   * Recupera le credenziali di autenticazione dalla pagina richiesta e le restituisce come un array associativo.
   * Se si restituisce null, l'autenticazione viene annullata.
   *
   * @param Request $request Pagina richiesta
   *
   * @return mixed|null Le credenziali dell'autenticazione o null
   */
  public function getCredentials(Request $request) {
    // protezione CSRF
    $csrfToken = $request->get('_csrf_token');
    $intention = 'authenticate';
    if (!$this->csrf->isTokenValid(new CsrfToken($intention, $csrfToken))) {
      $this->logger->error('Token CSRF non valido nella richiesta di registrazione.', array(
        'username' => $request->request->get('_username'),
        'ip' => $request->getClientIp(),
        ));
      throw new CustomUserMessageAuthenticationException('exception.invalid_csrf');
    }
    // restituisce le credenziali
    $username = $request->request->get('_username');
    $password = $request->request->get('_password');
    $request->getSession()->set(Security::LAST_USERNAME, $username);
    return array(
      'username' => $username,
      'password' => $password,
      'ip' => $request->getClientIp());
  }

  /**
   * Restituisce l'utente corrispondente alle credenziali fornite
   *
   * @param mixed $credentials Credenziali dell'autenticazione
   * @param UserProviderInterface $userProvider Gestore degli utenti
   *
   * @return UserInterface|null L'utente trovato o null
   */
  public function getUser($credentials, UserProviderInterface $userProvider) {
    // restituisce l'utente o null
    $user = $this->em->getRepository('App:Docente')->findOneByUsername($credentials['username']);
    if (!$user) {
      // docente non esiste
      $this->logger->error('Utente di tipo docente non valido nella richiesta di registrazione.', array(
        'username' => $credentials['username'],
        'ip' => $credentials['ip'],
        ));
      throw new CustomUserMessageAuthenticationException('exception.invalid_user');
    }
    // utente trovato
    return $user;
  }

  /**
   * Restituisce vero se le credenziali sono valide.
   * Qualsiasi altro valore restituito farà fallire l'autenticazione.
   * Si può anche generare un'eccezione per far fallire l'autenticazione.
   *
   * @param mixed $credentials Credenziali dell'autenticazione
   * @param UserInterface $user Utente corripondente alle credenziali
   *
   * @return bool Vero se le credenziali sono valide, falso altrimenti
   */
  public function checkCredentials($credentials, UserInterface $user) {
    // controllo se l'utente è abilitato
    if (!$user->getAbilitato()) {
      // utente disabilitato
      $this->logger->error('Docente disabilitato nella richiesta di registrazione.', array(
        'username' => $credentials['username'],
        'ip' => $credentials['ip'],
        ));
      throw new CustomUserMessageAuthenticationException('exception.invalid_user');
    }
    // controllo username/password
    $plainPassword = $credentials['password'];
    if ($this->encoder->isPasswordValid($user, $plainPassword)) {
      // legge configurazione
      $ip_conf = $this->em->getRepository('App:Configurazione')->findOneByParametro('ip_scuola');
      $ip = ($ip_conf === null ? array() : explode(',', $ip_conf->getValore()));
      // controlla IP
      if (!in_array($credentials['ip'], $ip)) {
        // errore, richiesta in non proveniente da scuola
        $this->logger->error('Docente con IP bloccato nella richiesta di registrazione.', array(
          'username' => $user->getUsername(),
          'ip' => $credentials['ip'],
          ));
        throw new CustomUserMessageAuthenticationException('exception.blocked_ip');
      }
      // validazione corretta
      $token_list = $user->recuperaChiavi();
      if ($token_list == null) {
        // crea i token per la prima volta
        $user->creaChiavi();
        $token_list = $user->recuperaChiavi();
        $this->em->flush();
      }
      // memorizza token in sessione
      $this->session->set('/APP/UTENTE/token1', $token_list[0]);
      $this->session->set('/APP/UTENTE/token2', $token_list[1]);
      $this->session->set('/APP/UTENTE/token3', $token_list[2]);
      // log azione
      $this->dblogger->write($user, $credentials['ip'], 'ACCESSO', 'Registrazione', __METHOD__, array(
        'Username' => $user->getUsername(),
        'Ruolo' => $user->getRoles()[0]
        ));
      // invia finto errore per evitare che l'utente possa accedere alle altre pagine del sito
      throw new CustomUserMessageAuthenticationException('exception.enrollment_ok');
    }
    // validazione fallita
    $this->logger->error('Password errata nella richiesta di registrazione.', array(
      'username' => $credentials['username'],
      'ip' => $credentials['ip'],
      ));
    throw new CustomUserMessageAuthenticationException('exception.invalid_credentials');
  }

  /**
   * Richiamata quando l'autenticazione è terminata con successo.
   *
   * @param Request $request Pagina richiesta
   * @param TokenInterface $token Token di autenticazione (contiene l'utente)
   * @param string $providerKey Chiave usata dal gestore della sicurezza (definita nel firewall)
   *
   * @return Response Pagina di risposta
   */
  public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey) {
    // non si arriva mai qui
  }

  /**
   * Richiamata quando l'autenticazione fallisce
   *
   * @param Request $request Pagina richiesta
   * @param AuthenticationException $exception Eccezione di autenticazione
   *
   * @return Response Pagina di risposta
   */
  public function onAuthenticationFailure(Request $request, AuthenticationException $exception) {
    if ($exception->getMessageKey() == 'exception.enrollment_ok') {
      // inoltra alla seconda pagina dell'autenticazione
      return new RedirectResponse($this->router->generate('login_token'));
    } else {
      // errore di autenticazione
      $request->getSession()->set(Security::AUTHENTICATION_ERROR, $exception);
      // redirect alla pagina di login
      return new RedirectResponse($this->router->generate('login_registrazione'));
    }
  }

  /**
   * Indica se l'autenticatore supporta o meno la gestione del cookie RICORDAMI.
   *
   * @return bool Vero se supportato il cookie RICORDAMI, falso altrimenti
   */
  public function supportsRememberMe() {
    // nessun supporto per il cookie RICORDAMI
    return false;
  }

  /**
   * Indica se l'autenticatore supporta o meno la richiesta attuale.
   *
   * @param Request $request Pagina richiesta
   *
   * @return bool Vero se supportato, falso altrimenti
   */
  public function supports(Request $request) {
    return ($request->getPathInfo() == '/login/registrazione/' && $request->isMethod('POST'));
  }

}

