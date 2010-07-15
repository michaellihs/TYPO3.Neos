<?php
declare(ENCODING = 'utf-8');
namespace F3\TYPO3\Domain\Model\Structure;

/*                                                                        *
 * This script belongs to the FLOW3 package "TYPO3".                      *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License as published by the Free   *
 * Software Foundation, either version 3 of the License, or (at your      *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General      *
 * Public License for more details.                                       *
 *                                                                        *
 * You should have received a copy of the GNU General Public License      *
 * along with the script.                                                 *
 * If not, see http://www.gnu.org/licenses/gpl.html                       *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * Testcase for the "Site" domain model
 *
 * @version $Id$
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
class SiteTest extends \F3\Testing\BaseTestCase {

	/**
	 * @test
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function aNameCanBeSetAndRetrievedFromTheSite() {
		$site = new \F3\TYPO3\Domain\Model\Structure\Site();
		$site->setName('My cool website');
		$this->assertSame('My cool website', $site->getName());
	}

	/**
	 * @test
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function theDefaultStateOfASiteIsOnline() {
		$site = new \F3\TYPO3\Domain\Model\Structure\Site();
		$this->assertSame(\F3\TYPO3\Domain\Model\Structure\Site::STATE_ONLINE, $site->getState());
	}

	/**
	 * @test
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function theStateCanBeSetAndRetrieved() {
		$site = new \F3\TYPO3\Domain\Model\Structure\Site();
		$site->setState(\F3\TYPO3\Domain\Model\Structure\Site::STATE_OFFLINE);
		$this->assertSame(\F3\TYPO3\Domain\Model\Structure\Site::STATE_OFFLINE, $site->getState());
	}

	/**
	 * @test
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function theSiteResourcesPackageKeyCanBeSetAndRetrieved() {
		$site = new \F3\TYPO3\Domain\Model\Structure\Site();
		$site->setSiteResourcesPackageKey('Foo');
		$this->assertSame('Foo', $site->getSiteResourcesPackageKey());
	}

	/**
	 * @test
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function getIndexNodeReturnsTheFirstNodeOnTheFirstLevelMatchingTheContextsLocale() {
		$locale1 = new \F3\FLOW3\I18n\Locale('de-DE');
		$locale2 = new \F3\FLOW3\I18n\Locale('en-EN');

		$mockContentContext = $this->getMock('F3\TYPO3\Domain\Service\ContentContext', array(), array(), '', FALSE);
		$mockContentContext->expects($this->any())->method('getLocale')->will($this->returnValue($locale1));

		$mockNode1 = $this->getMock('F3\TYPO3\Domain\Model\Structure\ContentNode', array(), array(), uniqid('ContentNode'), FALSE);
		$mockNode2 = $this->getMock('F3\TYPO3\Domain\Model\Structure\ContentNode', array(), array(), uniqid('ContentNode'), FALSE);

		$site = new \F3\TYPO3\Domain\Model\Structure\Site();
		$site->addChildNode($mockNode1, $locale1);
		$site->addChildNode($mockNode2, $locale2);

		$this->assertSame($mockNode1, $site->getIndexNode($mockContentContext));
	}

	/**
	 * @test
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	public function getIndexNodeReturnsNullIfNoMatchingNodeWasFound() {
		$locale1 = new \F3\FLOW3\I18n\Locale('de-DE');

		$mockContentContext = $this->getMock('F3\TYPO3\Domain\Service\ContentContext', array(), array(), '', FALSE);
		$mockContentContext->expects($this->any())->method('getLocale')->will($this->returnValue($locale1));

		$site = new \F3\TYPO3\Domain\Model\Structure\Site();

		$this->assertNull($site->getIndexNode($mockContentContext));
	}
}

?>