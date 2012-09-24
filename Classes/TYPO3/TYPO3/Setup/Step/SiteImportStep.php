<?php
namespace TYPO3\TYPO3\Setup\Step;

/*                                                                        *
 * This script belongs to the FLOW3 package "TYPO3.Setup".                *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 * of the License, or (at your option) any later version.                 *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\FLOW3\Annotations as FLOW3,
	TYPO3\Form\Core\Model\FormDefinition,
	\TYPO3\FLOW3\Utility\Files as Files;

/**
 * @FLOW3\Scope("singleton")
 */
class SiteImportStep extends \TYPO3\Setup\Step\AbstractStep {

	/**
	 * @var boolean
	 */
	protected $optional = TRUE;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\FLOW3\Package\PackageManagerInterface
	 */
	protected $packageManager;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\TYPO3\Domain\Repository\SiteRepository
	 */
	protected $siteRepository;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\TYPO3\Domain\Service\SiteImportService
	 */
	protected $siteImportService;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\TYPO3\Domain\Repository\DomainRepository
	 */
	protected $domainRepository;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Repository\NodeRepository
	 */
	protected $nodeRepository;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository
	 */
	protected $workspaceRepository;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\FLOW3\Persistence\PersistenceManagerInterface
	 */
	protected $persistenceManager;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\FLOW3\Mvc\FlashMessageContainer
	 */
	protected $flashMessageContainer;

	/**
	 * @var \TYPO3\Form\Finishers\ClosureFinisher
	 */
	protected $closureFinisher;

	/**
	 * @var \TYPO3\SiteKickstarter\Service\GeneratorService
	 * @FLOW3\Inject
	 */
	protected $generatorService;

	/**
	 * Returns the form definitions for the step
	 *
	 * @param \TYPO3\Form\Core\Model\FormDefinition $formDefinition
	 * @return void
	 */
	protected function buildForm(\TYPO3\Form\Core\Model\FormDefinition $formDefinition) {
		$page1 = $formDefinition->createPage('page1');

		$title = $page1->createElement('connectionSection', 'TYPO3.Form:Section');
		$title->setLabel('Import a site');

		$sitePackages = array();
		foreach ($this->packageManager->getActivePackages() as $package) {
			$packageMetaData = $package->getPackageMetaData();
			if (in_array('Site', $packageMetaData->getCategories())) {
				$sitePackages[$package->getPackageKey()] = $packageMetaData->getTitle();
			}
		}

		if (count($sitePackages) > 0) {
			$site = $title->createElement('site', 'TYPO3.Form:SingleSelectDropdown');
			$site->setLabel('Select a site');
			$site->setProperty('options', $sitePackages);
			$site->addValidator(new \TYPO3\FLOW3\Validation\Validator\NotEmptyValidator());

			$sites = $this->siteRepository->findAll();
			if ($sites->count() > 0) {
				$prune = $title->createElement('prune', 'TYPO3.Form:Checkbox');
				$prune->setLabel('Delete existing sites');
			}
		} else {
			$error = $title->createElement('error', 'TYPO3.Form:StaticText');
			$error->setProperty('text', 'No site packages were available, make sure you have an active site package');
			$error->setProperty('class', 'alert alert-warning');

		}

		$newPackageSection = $page1->createElement('newPackageSection', 'TYPO3.Form:Section');
		$newPackageSection->setLabel('Create a new site');
		$packageName = $newPackageSection->createElement('packageKey', 'TYPO3.Form:SingleLineText');
		$packageName->setLabel('Package Name (in form "Vendor.MyPackageName")');
		$packageName->addValidator(new \TYPO3\FLOW3\Validation\Validator\RegularExpressionValidator(array(
			'regularExpression' =>  \TYPO3\FLOW3\Package\PackageInterface::PATTERN_MATCH_PACKAGEKEY
		)));

		$siteName = $newPackageSection->createElement('siteName', 'TYPO3.Form:SingleLineText');
		$siteName->setLabel('Site Name');

		$step = $this;
		$callback = function(\TYPO3\Form\Core\Model\FinisherContext $finisherContext) use ($step) {
			$step->importSite($finisherContext);
		};
		$this->closureFinisher = new \TYPO3\Form\Finishers\ClosureFinisher();
		$this->closureFinisher->setOption('closure', $callback);
		$formDefinition->addFinisher($this->closureFinisher);
	}

	/**
	 * @param \TYPO3\Form\Core\Model\FinisherContext $finisherContext
	 * @return void
	 * @throws \TYPO3\Setup\Exception
	 */
	public function importSite(\TYPO3\Form\Core\Model\FinisherContext $finisherContext) {
		$formValues = $finisherContext->getFormRuntime()->getFormState()->getFormValues();

		if (isset($formValues['prune']) && intval($formValues['prune']) === 1) {
			$this->nodeRepository->removeAll();
			$this->workspaceRepository->removeAll();
			$this->domainRepository->removeAll();
			$this->siteRepository->removeAll();
			$this->persistenceManager->persistAll();
		}

		if (!empty($formValues['packageKey'])) {
			if ($this->packageManager->isPackageAvailable($formValues['packageKey'])) {
				throw new \TYPO3\Setup\Exception(sprintf('The package key "%s" already exists.', $formValues['packageKey']), 1346759486);
			}
			$packageKey = $formValues['packageKey'];
			$siteName = $formValues['packageKey'];

			$this->packageManager->createPackage($packageKey, NULL, Files::getUnixStylePath(Files::concatenatePaths(array(FLOW3_PATH_PACKAGES, 'Sites'))));
			$this->generatorService->generateSitesXml($packageKey, $siteName);
			$this->generatorService->generateSitesTypoScript($packageKey, $siteName);
			$this->generatorService->generateSitesTemplate($packageKey, $siteName);
			$this->packageManager->activatePackage($packageKey);
		} else {
			$packageKey = $formValues['site'];
		}


		if ($packageKey !== '') {
			try {
				$contentContext = new \TYPO3\TYPO3\Domain\Service\ContentContext('live');
				$this->nodeRepository->setContext($contentContext);
				$this->siteImportService->importFromPackage($packageKey);
			} catch (\Exception $exception) {
				$finisherContext->cancel();
				$this->flashMessageContainer->addMessage(new \TYPO3\FLOW3\Error\Error(sprintf('Error: During the import of the "Sites.xml" from the package "%s" an exception occurred: %s', $packageKey, $exception->getMessage())));
			}
		}
	}

}
?>