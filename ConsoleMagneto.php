<?php

/* geany_encoding=ISO-8859-15 */

require_once('simplehtmldom.php');
require_once('InfosChaine.php');
require_once('ConsoleFree.php');

require_once('Enregistrement.php');
require_once('EnregistrementFreebox.php');

class SessionException extends Exception{}

class ConsoleMagneto extends ConsoleFree{

	protected $opts = array(
		'box' => '0'
	);
	
	protected $data = array(
		'details' => null,
		'chaines' => null
	);

	public function __construct($opts = array()){
		parent::__construct();

		foreach($opts as $key=>$val){
			$this->opts[$key] = $val;
		}
	}

	/*
	 * retourne une liste d'enregistrements de classe EnregistrementFreebox
	 *
	*/
	public function lister(){

		$url = sprintf('https://adsl.free.fr/admin/magneto.pl?id=%s&idt=%s&detail=0&box=%s', 
			$this->id(),
			$this->idt(),
			$this->opts['box']
		);

		$data = $this->curl->get($url);
		$this->check_timeout($data);

		$html = new simple_html_dom($data);

		$list = $html->find('div[class=tr] table form');

		$list_infos = array();
		$names = array('canal', 'date', 'heure', 'duree', 'nom', 'nom');
		$names_unset = array('name', 'h', 'dur');

		foreach($list as $elem){

			$infos = array();
			$infos['form']['id'] = $elem->id;
			$infos['form']['method'] = $elem->method;
			$infos['form']['action'] = $elem->action;

			$infos1 = $elem->find('td strong');
			foreach($infos1 as $key=>$info)
				$infos[$names[$key]] = $info->innertext;
			
			$list2 = $elem->find('input[type=hidden]');

			foreach($list2 as $elem2){
				if(isset($elem2->attr['name']))
					$infos[$elem2->attr['name']] = $elem2->attr['value'];
			}
			
			$infos['canal'] = explode('<br>', $infos['canal']);
			
			# suppression des infos en double
			foreach($names_unset as $k)
				unset($infos[$k]);
	
			$list_infos[] = new EnregistrementFreebox($infos);

		}
		return $list_infos;
	}
	
/*
 * programmer :	permet de programmer un enregistrement
 * 
	* sp�cifications temporelles : date, dur�e, heure, minutes
	* emission : nom du fichier d'enregistrement,
	* service : identifiant num�rique sp�cifiant le canal de diffusion ; valeur possible $info->chaine('France 2')->service_id('.*auto.*')
	* chaine : identificant de la chaine ; valeur possible $info->chaine('France 2')->id()
	* where_id : identifiant du media-player (espace de stockage disque)
	* champs suppl�mentaire suppos� : 
	*   - repeat_a (liste des jours de la semaine ou l'enregistrement doit avoir lieu
	*   - sur Freebox V5 : <input type="checkbox" name="period" value="1" id="period_1" -> Lundi

*/

	public function programmer($enreg){


		$args = array();
		$list = array('date', 'heure', 'minutes', 'duree', 'where_id', 'emission');
		foreach($list as $k)
			$args[$k] = $enreg->$k;

		$args['submit']  = "PROGRAMMER L'ENREGISTREMENT";
		
		# force sur 2 digits.
		$args['minutes'] = sprintf('%02d', $args['minutes']);
		$args['heure'] = sprintf('%02d', $args['heure']);

		# si l'enregistrement provient d'un formulaire HTML, chaine et service ont d�ja la bonne valeur.
		if($enreg->service != null){
			$args['chaine'] = $enreg->chaine;
			$args['service'] = $enreg->service;
		} else{
			$infos = $this->infos_chaines();
			$chaine = $infos->find_by_name($enreg->chaine);
			
			$args['chaine']  = $chaine->id();
			$args['service'] = $chaine->service_id(sprintf('.*%s.*', $enreg->qualite));
		}

		$url = sprintf('https://adsl.free.fr/admin/magneto.pl?id=%s&idt=%s', 
			$this->id(),
			$this->idt());

		$data = $this->curl->post($url, $args);
		# file_put_contents(sprintf('var/programmer-%s-log.html', time()), $data);
		$this->check_timeout($data);

		if(preg_match('#Des erreurs sont survenues :#', $data)){
			$html = new simple_html_dom($data);
			$list = $html->find('div div[class=tr] strong span[style=]');
			$names = array();
			foreach($list as $elem){
				$names[] = utf8_decode($elem->plaintext);
			}
			$error = utf8_decode(join(' - ', $names));
			throw new Exception ("erreur de programmation : $error");
		}
	}
	
	# url : https://adsl.free.fr/admin/magneto.pl?id=XXX&idt=YYYY&detail=0&box=0
	# listes les BOXes : les boitiers multi-media ; il peut y avoir plusieurs boitiers dans le m�me domicile
	public function liste_boxes(){

		$url = sprintf('https://adsl.free.fr/admin/magneto.pl?id=%s&idt=%s&detail=0&box=%s', 
			$this->id(),
			$this->idt(),
			$this->opts['box']
		);

		$data = $this->curl->get($url);
		$this->check_timeout($data);

		$html = new simple_html_dom($data);

		# <div  id="content" class="television"> / <div class="block-container"> / <a href=...>
		$boxes = array();

		$list = $html->find('div[id=content] div[class=block-container] a');
		foreach($list as $elem){
			$name = utf8_decode($this->html_trim($elem->plaintext));
			$attribs = join(',', $elem->attr);
			if(preg_match('#box=(\d+)$#', $attribs, $values))
				$attribs = $values[1];
			else
				$attribs = null;
				
			$boxes[$name] = $attribs;
		}
		return $boxes;
	}	
	

	# retourne un enregistrement contenant des infos sur les disques et espaces disponibles
	#
	public function infos_disks(){
		# var disk_a = [{"free_size":195334180,"total_size":239347164,"label":"internal-disk","id":2,"mount_point":"/Disque dur/Enregistrements"}];
		if(preg_match('#var disk_a = \[(.*)\];#', $this->details_url(), $values)){
			return json_decode($values[1]);
		} else
			throw new Exception("Magneto : pas d'info sur l'espace disque");
	}
	
	# listes des chaines support�es.
	# les caract�res accentu�s posent probl�me et sont mal d�cod�s. Il est possible de les remplacer par des expression r�guli�re (.*)
	#
	public function infos_chaines(){
		
		if($this->data['chaines'] != null)
			return $this->data['chaines'];
	
		# var serv_a = [{"name":"TF1","id":1,"service":[{"pvr_mode":"public","desc":"TF1 (TNT)","id":847},{"pvr_mode":"private","desc":"TF1 (HD)","id":150},...
		if(preg_match('#var serv_a = (.*);#', $this->details_url(), $values)){
			$infos = json_decode(utf8_decode($values[1]));
			if($infos === NULL)
				throw new Exception(sprintf("Magneto : erreur d�codage infos chaines (%d)", json_last_error()));

			$list = new InfoList();

			foreach($infos as $info){
				$list->add(new InfosChaine($info));
			}

			$this->data['chaines'] = $list;
			return $list;

		} else
			throw new Exception("Magneto : pas d'info sur les chaines");
	}

	public function infos_chaines_json(){
		if(preg_match('#var serv_a = (.*);#', $this->details_url(), $values)){
			return $values[0];
		} else
			throw new Exception("Magneto : pas d'info sur les chaines");
	}
	# url : https://adsl.free.fr/admin/magneto.pl?id=XXX&idt=YYYY&detail=1
	# retourne en format JSON, la listes des chaines, des ID de diffusion par qualit� d'enregistrement
	# un cache permet d'�viter de r�cup�rer en double sur le serveur
	public function details_url(){
		# printf("ConsoleMagneto::detail_url()\n");
		if($this->data['details'] == null){
			$url = sprintf('https://adsl.free.fr/admin/magneto.pl?id=%s&idt=%s&detail=1', 
				$this->id(),
				$this->idt()
			);
			# printf("ConsoleMagneto::detail_url($url)\n");
			$this->data['details'] = $this->curl->get($url);
			$this->check_timeout($this->data['details']);
		}
		
		return $this->data['details'];
	}

	/* 
		- suppression d'un enregistrement programm�
	*/

	public function supprimer($id){

		$url = sprintf('https://adsl.free.fr/admin/magneto.pl?id=%s&idt=%s', 
			$this->id(),
			$this->idt());
		
		if(!isset($args['supp']))
			$args['supp'] = "Supprimer";
		$args['ide'] = $id;

		$data = $this->curl->post($url, $args);
		$this->check_timeout($data);
		
		if(preg_match("#Pas d'enregistrement programm#", $data))
			return false;
		else
			return true;
	}
	
	/*
	 * suppression d'enregistrements ; bas� sur une expression r�guli�re : 
	 *  ex: expr = .*test.*
	 *
	 */
	public function supprimer_expr($expr){
		$list = $this->lister();
		foreach($list as $enreg){
			if(preg_match("#$expr#", $enreg))
				$this->supprimer($enreg->ide);
		}
	}

	protected function html_trim($str){
		$str = html_entity_decode($str, ENT_COMPAT, 'ISO-8859-15');
		$str = preg_replace('#[\xA0 \n\r\t]+#', ' ', $str);

		return trim($str);
	}
	
	protected function check_timeout($data){
		$expr = 'Votre session a expir';
		if(stripos(utf8_encode($data), $expr)!== false){
			# file_put_contents(sprintf('var/session-timeout-%s-log.html', time()), $data);
			throw new SessionException("session timeout");
		}
		return false;
	}

}

?>
