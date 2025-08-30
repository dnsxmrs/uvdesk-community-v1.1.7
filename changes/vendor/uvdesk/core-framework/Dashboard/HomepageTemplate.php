<?php

namespace Webkul\UVDesk\CoreFrameworkBundle\Dashboard;

use Symfony\Component\Routing\RouterInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UserService;
use Webkul\UVDesk\CoreFrameworkBundle\Framework\ExtendableComponentInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Dashboard\Segments\HomepageSectionInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Dashboard\Segments\HomepageSectionItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class HomepageTemplate implements ExtendableComponentInterface
{
	CONST SECTION_TEMPLATE = '<div class="uv-brick"><div class="uv-brick-head"><h6>[[ TITLE ]]</h6><p>[[ DESCRIPTION ]]</p></div><div class="uv-brick-section">[[ COLLECTION ]]</div></div>';
	CONST SECTION_ITEM_TEMPLATE = '<a href="[[ PATH ]]"><div class="uv-brick-container"><div class="uv-brick-icon">[[ SVG ]]</div><p>[[ TITLE ]]</p></div></a>';

	private $sections = [];
	private $sectionItems = [];
	private $isOrganized = false;

	public function __construct(RouterInterface $router, UserService $userService, TranslatorInterface $translator)
	{
		$this->router = $router;
		$this->userService = $userService;
		$this->translator = $translator;
	}

	public function appendSection(HomepageSectionInterface $section, $tags = [])
	{
		$this->sections[] = $section;
	}

	public function appendSectionItem(HomepageSectionItemInterface $sectionItem, $tags = [])
	{
		$this->sectionItems[] = $sectionItem;
	}

	private function organizeCollection()
	{
		$references = [];
		
		// Sort segments alphabetically
		usort($this->sections, function($section_1, $section_2) {
			return strcasecmp($section_1::getTitle(), $section_2::getTitle());
		});

		// @TODO: Refactor!!!
		$findSectionByName = function(&$array, $name) {
			for ($i = 0; $i < count($array); $i++) {
				if (strtolower($array[$i]::getTitle()) === $name) {
					return array($i, $array[$i]);
				}
			}
		};

		// re-inserting users section
		$users_sec = $findSectionByName($this->sections, "users"); 
		array_splice($this->sections, $users_sec[0], 1);
		array_splice($this->sections, $findSectionByName($this->sections, "knowledgebase")[0] + 1, 0, [$users_sec[1]]);

		usort($this->sectionItems, function($item_1, $item_2) {
			return strcasecmp($item_1::getTitle(), $item_2::getTitle());
		});

		// Maintain array references
		foreach ($this->sections as $reference => $section) {
			$references[get_class($section)] = $reference;
		}

		// Iteratively add child segments to their respective parent segments
		foreach ($this->sectionItems as $sectionItem) {
			if (!array_key_exists($sectionItem::getSectionReferenceId(), $references)) {
				continue;

				// @TODO: Handle exception
				throw new \Exception("No dashboard section [" . $sectionItem::getSectionReferenceId() . "] found for section item " . $sectionItem::getTitle() . " [" . get_class($sectionItem) . "].");
			}

			$this->sections[$references[$sectionItem::getSectionReferenceId()]]->appendItem($sectionItem);
		}

		$this->isOrganized = true;
	}

	private function isSegmentAccessible($segment)
	{
		if ($segment::getRoles() != null) {
			$is_accessible = false;

			foreach ($segment::getRoles() as $accessRole) {
				if ($this->userService->isAccessAuthorized($accessRole)) {
					$is_accessible = true;
	
					break;
				}
			}

			return $is_accessible;
		}

		return true;
	}

	private function getAccessibleSegments()
	{
		$whitelist = [];

		// Filter segments based on user credentials
		foreach ($this->sections as $segment) {
			if (false == $this->isSegmentAccessible($segment)) {
				continue;
			}

			foreach ($segment->getItemCollection() as $childSegment) {
				if (false == $this->isSegmentAccessible($childSegment)) {
					continue;
				}

				$whitelist[get_class($segment)][] = get_class($childSegment);
			}
		}

		return $whitelist;
	}

	public function render()
	{
		if (false == $this->isOrganized) {
			$this->organizeCollection();
		}

		$html = '';
		$whitelist = $this->getAccessibleSegments();

		// Group sections by their titles for layout
		$groupedSections = [
			'row1' => [],
			'row2' => [],
		];

		foreach ($this->sections as $segment) {
			if (empty($whitelist[get_class($segment)])) {
				continue;
			}

			$title = strtolower($segment::getTitle());

			if (in_array($title, ['knowledgebase', 'productivity', 'settings'])) {
				$groupedSections['row1'][] = $segment;
			} elseif (in_array($title, ['users', 'reports', 'apps'])) {
				$groupedSections['row2'][] = $segment;
			} else {
				// Default to row2 if not matched
				$groupedSections['row2'][] = $segment;
			}
		}

		// Render rows with wrapper divs and section classes
		$html .= '<div class="homepage-row homepage-row-1">';
		foreach ($groupedSections['row1'] as $segment) {
			$sectionHtml = '';
			$references = $whitelist[get_class($segment)];

			foreach ($segment->getItemCollection() as $childSegment) {
				if (!in_array(get_class($childSegment), $references)) {
					continue;
				}

				$sectionHtml .= strtr(self::SECTION_ITEM_TEMPLATE, [
					'[[ SVG ]]' => $childSegment::getIcon(),
					'[[ TITLE ]]' => $this->translator->trans($childSegment::getTitle()),
					'[[ PATH ]]' => $this->router->generate($childSegment::getRouteName()),
				]);
			}

			$html .= '<div class="homepage-section homepage-section-large">';
			$html .= strtr(self::SECTION_TEMPLATE, [
				'[[ TITLE ]]' => $this->translator->trans($segment::getTitle()),
				'[[ DESCRIPTION ]]' => $this->translator->trans($segment::getDescription()),
				'[[ COLLECTION ]]' => $sectionHtml,
			]);
			$html .= '</div>';
		}
		$html .= '</div>';

		$html .= '<div class="homepage-row homepage-row-2">';
		foreach ($groupedSections['row2'] as $segment) {
			$sectionHtml = '';
			$references = $whitelist[get_class($segment)];

			foreach ($segment->getItemCollection() as $childSegment) {
				if (!in_array(get_class($childSegment), $references)) {
					continue;
				}

				$sectionHtml .= strtr(self::SECTION_ITEM_TEMPLATE, [
					'[[ SVG ]]' => $childSegment::getIcon(),
					'[[ TITLE ]]' => $this->translator->trans($childSegment::getTitle()),
					'[[ PATH ]]' => $this->router->generate($childSegment::getRouteName()),
				]);
			}

			$html .= '<div class="homepage-section homepage-section-small">';
			$html .= strtr(self::SECTION_TEMPLATE, [
				'[[ TITLE ]]' => $this->translator->trans($segment::getTitle()),
				'[[ DESCRIPTION ]]' => $this->translator->trans($segment::getDescription()),
				'[[ COLLECTION ]]' => $sectionHtml,
			]);
			$html .= '</div>';
		}
		$html .= '</div>';

		return $html;
	}
}
