<?php
App::uses('AppController', 'Controller');
App::uses('Folder', 'Utility');
App::uses('File', 'Utility');

/**
 * ShadowAttributes Controller
 *
 * Handles requests to edit attributes, add attributes
 *
 * @property ShadowAttribute $ShadowAttribute
 */
class ShadowAttributesController extends AppController {

	public $components = array('Acl', 'Security', 'RequestHandler');

	public $paginate = array(
			'limit' => 60,
			'maxLimit' => 9999,
		);

	public $helpers = array('Js' => array('Jquery'));

	public function beforeFilter() {
		parent::beforeFilter();

		$this->Security->validatePost = true;

		// convert uuid to id if present in the url, and overwrite id field
		if (isset($this->params->query['uuid'])) {
			$params = array(
					'conditions' => array('ShadowAttribute.uuid' => $this->params->query['uuid']),
					'recursive' => 0,
					'fields' => 'ShadowAttribute.id'
					);
			$result = $this->ShadowAttribute->find('first', $params);
			if (isset($result['ShadowAttribute']) && isset($result['ShadowAttribute']['id'])) {
				$id = $result['ShadowAttribute']['id'];
				$this->params->addParams(array('pass' => array($id))); // FIXME find better way to change id variable if uuid is found. params->url and params->here is not modified accordingly now
			}
		}

		// if not admin or own org, check private as well..
		if (!$this->_IsSiteAdmin()) {
			$this->paginate = Set::merge($this->paginate,array(
			'conditions' =>
					array('OR' =>
							array(
								'Event.org =' => $this->Auth->user('org'),
								'AND' => array(
									array('OR' => array(
											array('ShadowAttribute.private !=' => 1),
											array('ShadowAttribute.cluster =' => 1),
										)),
									array('OR' => array(
											array('Event.private !=' => 1),
											array('Event.cluster =' => 1),
										)),
			)))));
		}
	}

/**
 * accept method
 *
 * @return void
 *
 */
	// Accept a proposed edit and update the attribute
	public function accept($id = null) {
		if ($this->_isRest()) {
			throw new Exception('This feature is limited to interactive users only.');
		}
		$this->loadModel('Attribute');
		$this->ShadowAttribute->id = $id;
		$this->ShadowAttribute->recursive = -1;
		$this->ShadowAttribute->read();
		$shadow = $this->ShadowAttribute->data['ShadowAttribute'];
		// If the old_id is set to anything but 0 then we're dealing with a proposed edit to an existing attribute
		if ($shadow['old_id'] != 0) {
			// Find the live attribute by the shadow attribute's uuid, so we can begin editing it
			$this->Attribute->recursive = -1;
			$activeAttribute = $this->Attribute->findByUuid($this->ShadowAttribute->data['ShadowAttribute']['uuid']);

			// Send those away that shouldn't be able to see this
			if (!$this->_IsSiteAdmin()) {
				if (($activeAttribute['Event']['orgc'] != $this->Auth->user('org')) && ($this->Auth->user('org') != $this->ShadowAttribute->data['ShadowAttribute']['org']) || (!$this->checkAcl('edit') || !$this->checkAcl('publish'))) {
					$this->Session->setFlash(__('Invalid attribute.'));
					$this->redirect(array('controller' => 'events', 'action' => 'index'));
				}
			}
			// Update the live attribute with the shadow data
			$activeAttribute['Attribute']['value1'] = $shadow['value1'];
			$activeAttribute['Attribute']['value2'] = $shadow['value2'];
			$activeAttribute['Attribute']['value'] = $shadow['value'];
			$activeAttribute['Attribute']['type'] = $shadow['type'];
			$activeAttribute['Attribute']['category'] = $shadow['category'];
			$activeAttribute['Attribute']['to_ids'] = $shadow['to_ids'];
			$this->Attribute->save($activeAttribute['Attribute']);
			$this->ShadowAttribute->delete($id, $cascade = false);
			$this->loadModel('Event');
			$this->Event->recursive = -1;
			$this->Event->id = $activeAttribute['Attribute']['event_id'];
			// Unpublish the event, accepting a proposal is modifying the event after all
			$this->Event->saveField('published', 0);
			$this->Session->setFlash(__('Proposed change accepted', true), 'default', array());
			$this->redirect(array('controller' => 'events', 'action' => 'view', $activeAttribute['Attribute']['event_id']));
			return;
		} else {
			// If the old_id is set to 0, then we're dealing with a brand new proposed attribute
			// The idea is to load the event that the new attribute will be attached to, create an attribute to it and set the distribution equal to that of the event
			$this->loadModel('Event');
			$toDeleteId = $shadow['id'];

			// Stuff that we won't use in its current form for the attribute
			unset($shadow['email'], $shadow['org'], $shadow['id'], $shadow['old_id']);
			$this->Event->recursive = -1;
			$this->Event->read(null, $shadow['event_id']);
			$event = $this->Event->data['Event'];
			$attribute = $shadow;

			// set the distribution equal to that of the event
			$attribute['private'] = $event['private'];
			$attribute['cluster'] = $event['cluster'];
			$attribute['communitie'] = $event['communitie'];
			$this->Attribute->create();
			$this->Attribute->save($attribute);
			if ($this->ShadowAttribute->typeIsAttachment($shadow['type'])) {
				$this->_moveFile($toDeleteId, $this->Attribute->id, $shadow['event_id']);
			}
			$this->ShadowAttribute->delete($toDeleteId, $cascade = false);

			// unpublish the event, since adding an attribute modified it
			$this->Event->saveField('published', 0);
			$this->Session->setFlash(__('Proposed attribute accepted', true), 'default', array());
			$this->redirect(array('controller' => 'events', 'action' => 'view', $event['id']));
		}
	}

	// If we accept a proposed attachment, then the attachment itself needs to be moved from files/eventId/shadow/shadowId to files/eventId/attributeId
	private function _moveFile($shadowId, $newId, $eventId){
		$pathOld = APP . "files" . DS . $eventId . DS . "shadow" . DS . $shadowId;
		$pathNew = APP . "files" . DS . $eventId . DS . $newId;
		if (rename($pathOld, $pathNew)) {
			return true;
		} else {
			$this->Session->setFlash(__('Moving of the file that this attachment references failed.', true), 'default', array());
			$this->redirect(array('controller' => 'events', 'action' => 'view', $eventId));
		}
	}


/**
 * discard method
 *
 * @return void
 *
 */
	// This method will discard a proposed change. Users that can delete the proposals are the publishing users of the org that created the event and of the ones that created the proposal - in addition to site admins of course
	public function discard($id = null) {
		if ($this->_isRest()) {
			throw new Exception('This feature is limited to interactive users only.');
		}
		$this->ShadowAttribute->id = $id;
		$this->ShadowAttribute->read();
		$eventId = $this->ShadowAttribute->data['ShadowAttribute']['event_id'];
		$this->loadModel('Event');
		$this->Event->recursive = -1;
		$this->Event->id = $eventId;
		$this->Event->read();
		// Send those away that shouldn't be able to see this
		if (!$this->_IsSiteAdmin()) {
			if (($this->Event->data['Event']['orgc'] != $this->Auth->user('org')) && ($this->Auth->user('org') != $this->ShadowAttribute->data['ShadowAttribute']['org']) || (!$this->checkAction('perm_modify') || !$this->checkAction('perm_publish'))) {
				$this->Session->setFlash(__('Invalid attribute.'));
				$this->redirect(array('controller' => 'events', 'action' => 'index'));
			}
		}
		$this->ShadowAttribute->delete($id, $cascade = false);
		$this->Session->setFlash(__('Proposed change discarded', true), 'default', array());
		$this->redirect(array('controller' => 'events', 'action' => 'view', $eventId));
	}

/**
 * add method
 *
 * @return void
 *
 * @throws NotFoundException // TODO Exception
 */
	public function add($eventId = null) {
		if ($this->request->is('post')) {
			$this->loadModel('Event');

			// Give error if someone tried to submit a attribute with attachment or malware-sample type.
			// TODO change behavior attachment options - this is bad ... it should rather by a messagebox or should be filtered out on the view level
			if (isset($this->request->data['ShadowAttribute']['type']) && $this->ShadowAttribute->typeIsAttachment($this->request->data['ShadowAttribute']['type'])) {
				$this->Session->setFlash(__('Attribute has not been added: attachments are added by "Add attachment" button', true), 'default', array(), 'error');
				$this->redirect(array('controller' => 'events', 'action' => 'view', $this->request->data['ShadowAttribute']['event_id']));
			}
			//
			// multiple attributes in batch import
			//
			if ((isset($this->request->data['ShadowAttribute']['batch_import']) && $this->request->data['ShadowAttribute']['batch_import'] == 1)) {
				// make array from value field
				$attributes = explode("\n", $this->request->data['ShadowAttribute']['value']);
				$fails = "";	// will be used to keep a list of the lines that failed or succeeded
				$successes = "";
				// TODO loop-holes,
				// the value null value thing
				foreach ($attributes as $key => $attribute) {
					$attribute = trim($attribute);
					if (strlen($attribute) == 0)
					continue; // don't do anything for empty lines
					$this->ShadowAttribute->create();
					$this->request->data['ShadowAttribute']['value'] = $attribute; // set the value as the content of the single line
					$this->request->data['ShadowAttribute']['email'] = $this->Auth->user('email');
					$this->request->data['ShadowAttribute']['org'] = $this->Auth->user('org');
					// TODO loop-holes,
					// there seems to be a loop-hole in misp here
					// be it an create and not an update
					$this->ShadowAttribute->id = null;
					if ($this->ShadowAttribute->save($this->request->data)) {
						$successes .= " " . ($key + 1);
					} else {
						$fails .= " " . ($key + 1);
					}
				}
				// we added all the attributes,
				if ($fails) {
					// list the ones that failed
					if (!CakeSession::read('Message.flash')) {
						$this->Session->setFlash(__('The lines' . $fails . ' could not be saved. Please, try again.', true), 'default', array(), 'error');
					} else {
						$existingFlash = CakeSession::read('Message.flash');
						$this->Session->setFlash(__('The lines' . $fails . ' could not be saved. ' . $existingFlash['message'], true), 'default', array(), 'error');
					}
				}
				if ($successes) {
					// list the ones that succeeded
					$this->Session->setFlash(__('The lines' . $successes . ' have been saved', true));
				}

				$this->redirect(array('controller' => 'events', 'action' => 'view', $this->request->data['ShadowAttribute']['event_id']));

			} else {
				if (isset($this->request->data['ShadowAttribute']['uuid'])) {	// TODO here we should start RESTful dialog
					// check if the uuid already exists
				}

				//
				// single attribute
				//
				// create the attribute
				$this->ShadowAttribute->create();
				$savedId = $this->ShadowAttribute->getId();
				$this->request->data['ShadowAttribute']['email'] = $this->Auth->user('email');
				$this->request->data['ShadowAttribute']['org'] = $this->Auth->user('org');
				if ($this->ShadowAttribute->save($this->request->data)) {
					// inform the user and redirect
					$this->Session->setFlash(__('The attribute has been saved'));
					$this->redirect(array('controller' => 'events', 'action' => 'view', $this->request->data['ShadowAttribute']['event_id']));
				} else {
					if (!CakeSession::read('Message.flash')) {
						$this->Session->setFlash(__('The attribute could not be saved. Please, try again.'));
					}
				}
			}
		} else {
			// set the event_id in the form
			$this->request->data['ShadowAttribute']['event_id'] = $eventId;
		}

		// combobox for types
		$types = array_keys($this->ShadowAttribute->typeDefinitions);
		$types = $this->_arrayToValuesIndexArray($types);
		$this->set('types', $types);
		// combobos for categories
		$categories = $this->ShadowAttribute->validate['category']['rule'][1];
		array_pop($categories);
		$categories = $this->_arrayToValuesIndexArray($categories);
		$this->set('categories', compact('categories'));
		$this->loadModel('Event');
		$events = $this->Event->findById($eventId);
		// combobox for distribution
		$count = 0;

		$this->set('attrDescriptions', $this->ShadowAttribute->fieldDescriptions);
		$this->set('typeDefinitions', $this->ShadowAttribute->typeDefinitions);
		$this->set('categoryDefinitions', $this->ShadowAttribute->categoryDefinitions);
	}

	public function download($id = null) {
		$this->ShadowAttribute->id = $id;
		if (!$this->ShadowAttribute->exists()) {
			throw new NotFoundException(__('Invalid ShadowAttribute'));
		}

		$this->ShadowAttribute->read();
		$path = APP . "files" . DS . $this->ShadowAttribute->data['ShadowAttribute']['event_id'] . DS . 'shadow' . DS;
		$file = $this->ShadowAttribute->data['ShadowAttribute']['id'];
		$filename = '';
		if ('attachment' == $this->ShadowAttribute->data['ShadowAttribute']['type']) {
			$filename = $this->ShadowAttribute->data['ShadowAttribute']['value'];
			$fileExt = pathinfo($filename, PATHINFO_EXTENSION);
			$filename = substr($filename, 0, strlen($filename) - strlen($fileExt) - 1);
		} elseif ('malware-sample' == $this->ShadowAttribute->data['ShadowAttribute']['type']) {
			$filenameHash = explode('|', $this->ShadowAttribute->data['ShadowAttribute']['value']);
			$filename = $filenameHash[0];
			$filename = substr($filenameHash[0], strrpos($filenameHash[0], '\\'));
			$fileExt = "zip";
		} else {
			throw new NotFoundException(__('ShadowAttribute not an attachment or malware-sample'));
		}

		$this->viewClass = 'Media';
		$params = array(
					'id'		=> $file,
					'name'		=> $filename,
					'extension' => $fileExt,
					'download'	=> true,
					'path'		=> $path
		);
		$this->set($params);
	}

/**
 * add_attachment method
 *
 * @return void
 * @throws InternalErrorException
 */
	public function add_attachment($eventId = null) {
		if ($this->request->is('post')) {
			$this->loadModel('Event');
			// Check if there were problems with the file upload
			// only keep the last part of the filename, this should prevent directory attacks
			$filename = basename($this->request->data['ShadowAttribute']['value']['name']);
			$tmpfile = new File($this->request->data['ShadowAttribute']['value']['tmp_name']);
			if ((isset($this->request->data['ShadowAttribute']['value']['error']) && $this->request->data['ShadowAttribute']['value']['error'] == 0) ||
					(!empty( $this->request->data['ShadowAttribute']['value']['tmp_name']) && $this->request->data['ShadowAttribute']['value']['tmp_name'] != 'none')
			) {
				if (!is_uploaded_file($tmpfile->path))
					throw new InternalErrorException('PHP says file was not uploaded. Are you attacking me?');
			} else {
				$this->Session->setFlash(__('There was a problem to upload the file.', true), 'default', array(), 'error');
				$this->redirect(array('controller' => 'events', 'action' => 'view', $this->request->data['ShadowAttribute']['event_id']));
			}

			$this->Event->id = $this->request->data['ShadowAttribute']['event_id'];
			// save the file-info in the database
			$this->ShadowAttribute->create();
			if ($this->request->data['ShadowAttribute']['malware']) {
				$this->request->data['ShadowAttribute']['type'] = "malware-sample";
				// Validate filename
				if (!preg_match('@[\w-,\s]+\.[A-Za-z0-9_]{2,4}$@', $filename)) throw new Exception ('Filename not allowed');
				$this->request->data['ShadowAttribute']['value'] = $filename . '|' . $tmpfile->md5(); // TODO gives problems with bigger files
				$this->request->data['ShadowAttribute']['to_ids'] = 1; // LATER let user choose to send this to IDS
			} else {
				$this->request->data['ShadowAttribute']['type'] = "attachment";
				// Validate filename
				if (!preg_match('@[\w-,\s]+\.[A-Za-z0-9_]{2,4}$@', $filename)) throw new Exception ('Filename not allowed');
				$this->request->data['ShadowAttribute']['value'] = $filename;
				$this->request->data['ShadowAttribute']['to_ids'] = 0;
			}
			$this->request->data['ShadowAttribute']['uuid'] = String::uuid();
			$this->request->data['ShadowAttribute']['batch_import'] = 0;
			$this->request->data['ShadowAttribute']['email'] = $this->Auth->user('email');
			$this->request->data['ShadowAttribute']['org'] = $this->Auth->user('org');
			if ($this->ShadowAttribute->save($this->request->data)) {
				// ShadowAttribute saved correctly in the db
			} else {
				$this->Session->setFlash(__('The ShadowAttribute could not be saved. Did you already upload this file?'));
				$this->redirect(array('controller' => 'events', 'action' => 'view', $this->request->data['ShadowAttribute']['event_id']));
			}

			// no errors in file upload, entry already in db, now move the file where needed and zip it if required.
			// no sanitization is required on the filename, path or type as we save
			// create directory structure
			if (PHP_OS == 'WINNT') {
				$rootDir = APP . "files" . DS . $this->request->data['ShadowAttribute']['event_id'] . DS . "shadow";
			} else {
				$rootDir = APP . DS . "files" . DS . $this->request->data['ShadowAttribute']['event_id'] . DS . "shadow";
			}
			$dir = new Folder($rootDir, true);
			// move the file to the correct location
			$destpath = $rootDir . DS . $this->ShadowAttribute->id; // id of the new ShadowAttribute in the database
			$file = new File ($destpath);
			$zipfile = new File ($destpath . '.zip');
			$fileInZip = new File($rootDir . DS . $filename); // FIXME do sanitization of the filename

			if ($file->exists() || $zipfile->exists() || $fileInZip->exists()) {
				// this should never happen as the ShadowAttribute id should be unique
				$this->Session->setFlash(__('Attachment with this name already exist in this event.', true), 'default', array(), 'error');
				// remove the entry from the database
				$this->ShadowAttribute->delete();
				$this->redirect(array('controller' => 'events', 'action' => 'view', $this->request->data['ShadowAttribute']['event_id']));
			}
			if (!move_uploaded_file($tmpfile->path, $file->path)) {
				$this->Session->setFlash(__('Problem with uploading attachment. Cannot move it to its final location.', true), 'default', array(), 'error');
				// remove the entry from the database
				$this->ShadowAttribute->delete();
				$this->redirect(array('controller' => 'events', 'action' => 'view', $this->request->data['ShadowAttribute']['event_id']));
			}

			// zip and password protect the malware files
			if ($this->request->data['ShadowAttribute']['malware']) {
				// TODO check if CakePHP has no easy/safe wrapper to execute commands
				$execRetval = '';
				$execOutput = array();
				rename($file->path, $fileInZip->path); // TODO check if no workaround exists for the current filtering mechanisms
				if (PHP_OS == 'WINNT') {
					exec("zip -j -P infected " . $zipfile->path . ' "' . $fileInZip->path . '"', $execOutput, $execRetval);
				} else {
					exec("zip -j -P infected " . $zipfile->path . ' "' . addslashes($fileInZip->path) . '"', $execOutput, $execRetval);
				}
				if ($execRetval != 0) {	// not EXIT_SUCCESS
					$this->Session->setFlash(__('Problem with zipping the attachment. Please report to administrator. ' . $execOutput, true), 'default', array(), 'error');
					// remove the entry from the database
					$this->ShadowAttribute->delete();
					$fileInZip->delete();
					$file->delete();
					$this->redirect(array('controller' => 'events', 'action' => 'view', $this->request->data['ShadowAttribute']['event_id']));
				};
				$fileInZip->delete();	// delete the original not-zipped-file
				rename($zipfile->path, $file->path); // rename the .zip to .nothing
			}

			// everything is done, now redirect to event view
			$this->Session->setFlash(__('The attachment has been uploaded'));
			$this->redirect(array('controller' => 'events', 'action' => 'view', $this->request->data['ShadowAttribute']['event_id']));

		} else {
			// set the event_id in the form
			$this->request->data['ShadowAttribute']['event_id'] = $eventId;
			$this->loadModel('Event');
			$events = $this->Event->findById($eventId);
		}

		// combobox for categories
		$categories = $this->ShadowAttribute->validate['category']['rule'][1];
		// just get them with attachments..
		$selectedCategories = array();
		foreach ($categories as $category) {
			if (isset($this->ShadowAttribute->categoryDefinitions[$category])) {
				$types = $this->ShadowAttribute->categoryDefinitions[$category]['types'];
				$alreadySet = false;
				foreach ($types as $type) {
					if ($this->ShadowAttribute->typeIsAttachment($type) && !$alreadySet) {
						// add to the whole..
						$selectedCategories[] = $category;
						$alreadySet = true;
						continue;
					}
				}
			}
		};
		$categories = $this->_arrayToValuesIndexArray($selectedCategories);
		$this->set('categories',$categories);

		$this->set('attrDescriptions', $this->ShadowAttribute->fieldDescriptions);
		$this->set('typeDefinitions', $this->ShadowAttribute->typeDefinitions);
		$this->set('categoryDefinitions', $this->ShadowAttribute->categoryDefinitions);

		$this->set('zippedDefinitions', $this->ShadowAttribute->zippedDefinitions);
		$this->set('uploadDefinitions', $this->ShadowAttribute->uploadDefinitions);

	}

/**
 * edit method
 *
 * @param string $id
 * @return void
 * @throws NotFoundException
 */
	// Propose an edit to an attribute
	public function edit($id = null) {
		$this->loadModel('Attribute');
		$this->Attribute->id = $id;
		if (!$this->Attribute->exists()) {
			throw new NotFoundException(__('Invalid Attribute'));
		}
		$this->Attribute->read();
		if ($this->_isRest()) {
			throw new Exception ('Proposing a change to an attribute can only be done via the interactive interface.');
		}
		$uuid = $this->Attribute->data['Attribute']['uuid'];
		if (!$this->_IsSiteAdmin()) {
			// check for non-private and re-read CHANGE THIS TO NON-PRIVATE AND OTHER ORG
			if (($this->Attribute->data['Attribute']['private'] == 1 && $this->Attribute->data['Attribute']['Cluster'] == 0) || ($this->Attribute->data['Event']['org'] == $this->Auth->user('org'))) {
				$this->Session->setFlash(__('Invalid Attribute.'));
				$this->redirect(array('controller' => 'events', 'action' => 'index'));
			}
		}

		// Check if the attribute is an attachment, if yes, block the type and the value fields from being edited.
		$eventId = $this->Attribute->data['Attribute']['event_id'];
		if ('attachment' == $this->Attribute->data['Attribute']['type'] || 'malware-sample' == $this->Attribute->data['Attribute']['type'] ) {
			$this->set('attachment', true);
			$attachment = true;
		} else {
			$this->set('attachment', false);
			$attachment = false;
		}

		if ($this->request->is('post') || $this->request->is('put')) {
			$existingAttribute = $this->Attribute->findByUuid($uuid);
			$this->request->data['ShadowAttribute']['old_id'] = $existingAttribute['Attribute']['id'];
			$this->request->data['ShadowAttribute']['uuid'] = $existingAttribute['Attribute']['uuid'];
			$this->request->data['ShadowAttribute']['event_id'] = $existingAttribute['Attribute']['event_id'];
			if ($attachment) $this->request->data['ShadowAttribute']['value'] = $existingAttribute['Attribute']['value'];
			if ($attachment) $this->request->data['ShadowAttribute']['type'] = $existingAttribute['Attribute']['type'];
			$this->request->data['ShadowAttribute']['org'] =  $this->Auth->user('org');
			$this->request->data['ShadowAttribute']['email'] = $this->Auth->user('email');
			$fieldList = array('category', 'type', 'value1', 'value2', 'to_ids', 'value', 'org');
			if ($this->ShadowAttribute->save($this->request->data)) {
				$this->Session->setFlash(__('The proposed Attribute has been saved'));
				$this->redirect(array('controller' => 'events', 'action' => 'view', $eventId));
			} else {
				$this->Session->setFlash(__('The ShadowAttribute could not be saved. Please, try again.'));
			}
		} else {
			// Read the attribute that we're about to edit
			$this->ShadowAttribute->create();
			$this->Attribute->recursive = -1;
			$request = $this->Attribute->read(null, $id);
			$request['ShadowAttribute'] = $request['Attribute'];
			$this->request->data = $request;
			unset($this->request->data['ShadowAttribute']['id']);
		}

		// combobox for types
		$types = array_keys($this->ShadowAttribute->typeDefinitions);
		$types = $this->_arrayToValuesIndexArray($types);
		$this->set('types', $types);
		// combobox for categories
		$categories = $this->ShadowAttribute->validate['category']['rule'][1];
		array_pop($categories); // remove that last empty/space option
		$categories = $this->_arrayToValuesIndexArray($categories);
		$this->set('categories', $categories);

		$this->set('attrDescriptions', $this->ShadowAttribute->fieldDescriptions);
		$this->set('typeDefinitions', $this->ShadowAttribute->typeDefinitions);
		$this->set('categoryDefinitions', $this->ShadowAttribute->categoryDefinitions);
	}
}
