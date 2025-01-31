<?php

namespace SilverStripe\Subsites\Tests;

use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Subsites\Model\SubsiteDomain;
use SilverStripe\Subsites\State\SubsiteState;
use UnexpectedValueException;

class SubsiteTest extends BaseSubsiteTest
{
    protected static $fixture_file = 'SubsiteTest.yml';

    protected $usesTransactions = false;

    /**
     * Original value of $_REQUEST
     *
     * @var array
     */
    protected $origServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()
            ->set(Director::class, 'alternate_base_url', '/')
            ->set(Subsite::class, 'strict_subdomain_matching', false)
            ->set(Subsite::class, 'write_hostmap', false);

        $this->origServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->origServer;

        parent::tearDown();
    }

    /**
     * Create a new subsite from the template and verify that all the template's pages are copied
     */
    public function testSubsiteCreation()
    {
        // Create the instance
        $template = $this->objFromFixture(Subsite::class, 'main');

        // Test that changeSubsite is working
        Subsite::changeSubsite($template->ID);
        $this->assertEquals($template->ID, SubsiteState::singleton()->getSubsiteId());
        $tmplStaff = $this->objFromFixture('Page', 'staff');
        $tmplHome = DataObject::get_one('Page', "\"URLSegment\" = 'home'");

        // Publish all the pages in the template, testing that DataObject::get only returns pages
        // from the chosen subsite
        $pages = DataObject::get(SiteTree::class);
        $totalPages = $pages->count();
        foreach ($pages as $page) {
            $this->assertEquals($template->ID, $page->SubsiteID);
            $page->copyVersionToStage('Stage', 'Live');
        }

        // Create a new site
        $subsite = $template->duplicate();

        // Check title
        $this->assertEquals($subsite->Title, $template->Title);

        // Another test that changeSubsite is working
        $subsite->activate();

        $siteHome = DataObject::get_one('Page', "\"URLSegment\" = 'home'");
        $this->assertNotEquals($siteHome, false, 'Home Page for subsite not found');
        $this->assertEquals(
            $subsite->ID,
            $siteHome->SubsiteID,
            'createInstance() copies existing pages retaining the same URLSegment'
        );

        Subsite::changeSubsite(0);
    }

    /**
     * Confirm that domain lookup is working
     */
    public function testDomainLookup()
    {
        // Clear existing fixtures
        foreach (DataObject::get(Subsite::class) as $subsite) {
            $subsite->delete();
        }
        foreach (DataObject::get(SubsiteDomain::class) as $domain) {
            $domain->delete();
        }

        // Much more expressive than YML in this case
        $subsite1 = $this->createSubsiteWithDomains([
            'one.example.org' => true,
            'one.*' => false,
        ]);
        $subsite2 = $this->createSubsiteWithDomains([
            'two.mysite.com' => true,
            '*.mysite.com' => false,
            'subdomain.onmultiplesubsites.com' => false,
        ]);
        $subsite3 = $this->createSubsiteWithDomains([
            'three.*' => true, // wildcards in primary domain are not recommended
            'subdomain.unique.com' => false,
            '*.onmultiplesubsites.com' => false,
        ]);

        $this->assertEquals(
            $subsite3->ID,
            Subsite::getSubsiteIDForDomain('subdomain.unique.com'),
            'Full unique match'
        );

        $this->assertEquals(
            $subsite1->ID,
            Subsite::getSubsiteIDForDomain('one.example.org'),
            'Full match, doesn\'t complain about multiple matches within a single subsite'
        );

        $failed = false;
        try {
            Subsite::getSubsiteIDForDomain('subdomain.onmultiplesubsites.com');
        } catch (UnexpectedValueException $e) {
            $failed = true;
        }
        $this->assertTrue(
            $failed,
            'Fails on multiple matches with wildcard vs. www across multiple subsites'
        );

        $this->assertEquals(
            $subsite1->ID,
            Subsite::getSubsiteIDForDomain('one.unique.com'),
            'Fuzzy match suffixed with wildcard (rule "one.*")'
        );

        $this->assertEquals(
            $subsite2->ID,
            Subsite::getSubsiteIDForDomain('two.mysite.com'),
            'Matches correct subsite for rule'
        );

        $this->assertEquals(
            $subsite2->ID,
            Subsite::getSubsiteIDForDomain('other.mysite.com'),
            'Fuzzy match prefixed with wildcard (rule "*.mysite.com")'
        );

        $this->assertEquals(
            0,
            Subsite::getSubsiteIDForDomain('unknown.madeup.com'),
            "Doesn't match unknown subsite"
        );
    }

    public function testStrictSubdomainMatching()
    {
        // Clear existing fixtures
        foreach (DataObject::get(Subsite::class) as $subsite) {
            $subsite->delete();
        }
        foreach (DataObject::get(SubsiteDomain::class) as $domain) {
            $domain->delete();
        }

        // Much more expressive than YML in this case
        $subsite1 = $this->createSubsiteWithDomains([
            'example.org' => true,
            'example.com' => false,
            '*.wildcard.com' => false,
        ]);
        $subsite2 = $this->createSubsiteWithDomains([
            'www.example.org' => true,
            'www.wildcard.com' => false,
        ]);

        Config::modify()->set(Subsite::class, 'strict_subdomain_matching', false);

        $this->assertEquals(
            $subsite1->ID,
            Subsite::getSubsiteIDForDomain('example.org'),
            'Exact matches without strict checking when not using www prefix'
        );
        $this->assertEquals(
            $subsite1->ID,
            Subsite::getSubsiteIDForDomain('example.org:1123'),
            'Exact matches without strict checking when not using www prefix and ignores port'
        );
        $this->assertEquals(
            $subsite1->ID,
            Subsite::getSubsiteIDForDomain('www.example.org'),
            'Matches without strict checking when using www prefix, '
            .'still matching first domain regardless of www prefix  (falling back to subsite primary key ordering)'
        );
        $this->assertEquals(
            $subsite1->ID,
            Subsite::getSubsiteIDForDomain('www.example.org:9923'),
            'Matches without strict checking when using www prefix, '
            .'still matching first domain without prefix  (falling back to primary key ordering and ignoring port)'
        );
        $this->assertEquals(
            $subsite1->ID,
            Subsite::getSubsiteIDForDomain('www.example.com'),
            'Fuzzy matches without strict checking with www prefix'
        );
        $this->assertEquals(
            0,
            Subsite::getSubsiteIDForDomain('www.wildcard.com'),
            'Doesn\'t match www prefix without strict check, even if a wildcard subdomain is in place'
        );

        Config::modify()->set(Subsite::class, 'strict_subdomain_matching', true);

        $this->assertEquals(
            $subsite1->ID,
            Subsite::getSubsiteIDForDomain('example.org'),
            'Matches with strict checking when not using www prefix'
        );
        $this->assertEquals(
            $subsite1->ID,
            Subsite::getSubsiteIDForDomain('example.org:123'),
            'Matches with strict checking when not using www prefix and ignores port'
        );
        $this->assertEquals(
            $subsite2->ID, // not 1
            Subsite::getSubsiteIDForDomain('www.example.org'),
            'Matches with strict checking when using www prefix'
        );
        $this->assertEquals(
            0,
            Subsite::getSubsiteIDForDomain('www.example.com'),
            'Doesn\'t fuzzy match with strict checking when using www prefix'
        );
        $failed = false;
        try {
            Subsite::getSubsiteIDForDomain('www.wildcard.com');
        } catch (UnexpectedValueException $e) {
            $failed = true;
        }
        $this->assertTrue(
            $failed,
            'Fails on multiple matches with strict checking and wildcard vs. www'
        );
    }

    protected function createSubsiteWithDomains($domains)
    {
        $subsite = new Subsite([
            'Title' => 'My Subsite'
        ]);
        $subsite->write();
        foreach ($domains as $domainStr => $isPrimary) {
            $domain = new SubsiteDomain([
                'Domain' => $domainStr,
                'IsPrimary' => $isPrimary,
                'SubsiteID' => $subsite->ID
            ]);
            $domain->write();
        }

        return $subsite;
    }

    /**
     * Test the Subsite->domain() method
     */
    public function testDefaultDomain()
    {
        $this->assertEquals(
            'one.example.org',
            $this->objFromFixture(Subsite::class, 'domaintest1')->domain()
        );

        $this->assertEquals(
            'two.mysite.com',
            $this->objFromFixture(Subsite::class, 'domaintest2')->domain()
        );

        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $this->assertEquals(
            'three.example.org',
            $this->objFromFixture(Subsite::class, 'domaintest3')->domain()
        );

        $_SERVER['HTTP_HOST'] = 'mysite.example.org';
        $this->assertEquals(
            'three.mysite.example.org',
            $this->objFromFixture(Subsite::class, 'domaintest3')->domain()
        );

        $this->assertEquals($_SERVER['HTTP_HOST'], singleton(Subsite::class)->PrimaryDomain);
        $this->assertEquals(
            'http://' . $_SERVER['HTTP_HOST'] . Director::baseURL(),
            singleton(Subsite::class)->absoluteBaseURL()
        );
    }

    /**
     * Tests that Subsite and SubsiteDomain both respect http protocol correctly
     *
     * @param string $class Fixture class name
     * @param string $identifier Fixture identifier
     * @param bool $currentIsSecure Whether the current base URL should be secure
     * @param string $expected The expected base URL for the subsite or subsite domain
     * @dataProvider domainProtocolProvider
     */
    public function testDomainProtocol($class, $identifier, $currentIsSecure, $expected)
    {
        /** @var Subsite|SubsiteDomain $model */
        $model = $this->objFromFixture($class, $identifier);
        $protocol = $currentIsSecure ? 'https' : 'http';
        Config::modify()->set(Director::class, 'alternate_base_url', $protocol . '://www.mysite.com');
        $this->assertSame($expected, $model->absoluteBaseURL());
    }

    public function domainProtocolProvider()
    {
        return [
            [Subsite::class, 'domaintest2', false, 'http://two.mysite.com/'],
            [SubsiteDomain::class, 'dt2a', false, 'http://two.mysite.com/'],
            [SubsiteDomain::class, 'dt2b', false, 'http://subsite.mysite.com/'],
            [Subsite::class, 'domaintest4', false, 'https://www.primary.com/'],
            [SubsiteDomain::class, 'dt4a', false, 'https://www.primary.com/'],
            [SubsiteDomain::class, 'dt4b', false, 'http://www.secondary.com/'],
            [Subsite::class, 'domaintest5', false, 'http://www.tertiary.com/'],
            [SubsiteDomain::class, 'dt5', false,  'http://www.tertiary.com/'],
            [Subsite::class, 'domaintest2', true, 'https://two.mysite.com/'],
            [SubsiteDomain::class, 'dt2a', true, 'https://two.mysite.com/'],
            [SubsiteDomain::class, 'dt2b', true, 'https://subsite.mysite.com/'],
            [Subsite::class, 'domaintest4', true, 'https://www.primary.com/'],
            [SubsiteDomain::class, 'dt4a', true, 'https://www.primary.com/'],
            [SubsiteDomain::class, 'dt4b', true, 'http://www.secondary.com/'],
            [Subsite::class, 'domaintest5', true, 'http://www.tertiary.com/'],
            [SubsiteDomain::class, 'dt5', true, 'http://www.tertiary.com/'],
        ];
    }

    public function testAllSites()
    {
        $subsites = Subsite::all_sites();
        $this->assertListEquals([
            ['Title' => 'Main site'],
            ['Title' => 'Template'],
            ['Title' => 'Subsite1 Template'],
            ['Title' => 'Subsite2 Template'],
            ['Title' => 'Test 1'],
            ['Title' => 'Test 2'],
            ['Title' => 'Test 3'],
            ['Title' => 'Test Non-SSL'],
            ['Title' => 'Test SSL'],
            ['Title' => 'Test Vagrant VM on port 8080'],
            ['Title' => 'Locale subsite'],
        ], $subsites, 'Lists all subsites');
    }

    public function testAllAccessibleSites()
    {
        $member = $this->objFromFixture(Member::class, 'subsite1member');

        $subsites = Subsite::all_accessible_sites(true, 'Main site', $member);
        $this->assertListEquals([
            ['Title' => 'Subsite1 Template']
        ], $subsites, 'Lists member-accessible sites.');
    }

    /**
     * Test Subsite::accessible_sites()
     */
    public function testAccessibleSites()
    {
        $member1Sites = Subsite::accessible_sites(
            'CMS_ACCESS_CMSMain',
            false,
            null,
            $this->objFromFixture(Member::class, 'subsite1member')
        );
        $member1SiteTitles = $member1Sites->column('Title');
        sort($member1SiteTitles);
        $this->assertEquals('Subsite1 Template', $member1SiteTitles[0], 'Member can get to a subsite via a group');

        $adminSites = Subsite::accessible_sites(
            'CMS_ACCESS_CMSMain',
            false,
            null,
            $this->objFromFixture(Member::class, 'admin')
        );
        $adminSiteTitles = $adminSites->column('Title');
        sort($adminSiteTitles);
        $this->assertEquals([
            'Locale subsite',
            'Subsite1 Template',
            'Subsite2 Template',
            'Template',
            'Test 1',
            'Test 2',
            'Test 3',
            'Test Non-SSL',
            'Test SSL',
            'Test Vagrant VM on port 8080'
        ], array_values($adminSiteTitles ?? []));

        $member2Sites = Subsite::accessible_sites(
            'CMS_ACCESS_CMSMain',
            false,
            null,
            $this->objFromFixture(Member::class, 'subsite1member2')
        );
        $member2SiteTitles = $member2Sites->column('Title');
        sort($member2SiteTitles);
        $this->assertEquals('Subsite1 Template', $member2SiteTitles[1], 'Member can get to subsite via a group role');
    }

    public function testhasMainSitePermission()
    {
        $admin = $this->objFromFixture(Member::class, 'admin');
        $subsite1member = $this->objFromFixture(Member::class, 'subsite1member');
        $subsite1admin = $this->objFromFixture(Member::class, 'subsite1admin');
        $allsubsitesauthor = $this->objFromFixture(Member::class, 'allsubsitesauthor');

        $this->assertTrue(
            Subsite::hasMainSitePermission($admin),
            'Default permissions granted for super-admin'
        );
        $this->assertTrue(
            Subsite::hasMainSitePermission($admin, ['ADMIN']),
            'ADMIN permissions granted for super-admin'
        );
        $this->assertFalse(
            Subsite::hasMainSitePermission($subsite1admin, ['ADMIN']),
            'ADMIN permissions (on main site) denied for subsite1 admin'
        );
        $this->assertFalse(
            Subsite::hasMainSitePermission($subsite1admin, ['CMS_ACCESS_CMSMain']),
            'CMS_ACCESS_CMSMain (on main site) denied for subsite1 admin'
        );
        $this->assertFalse(
            Subsite::hasMainSitePermission($allsubsitesauthor, ['ADMIN']),
            'ADMIN permissions (on main site) denied for CMS author with edit rights on all subsites'
        );
        $this->assertTrue(
            Subsite::hasMainSitePermission($allsubsitesauthor, ['CMS_ACCESS_CMSMain']),
            'CMS_ACCESS_CMSMain (on main site) granted for CMS author with edit rights on all subsites'
        );
        $this->assertFalse(
            Subsite::hasMainSitePermission($subsite1member, ['ADMIN']),
            'ADMIN (on main site) denied for subsite1 subsite1 cms author'
        );
        $this->assertFalse(
            Subsite::hasMainSitePermission($subsite1member, ['CMS_ACCESS_CMSMain']),
            'CMS_ACCESS_CMSMain (on main site) denied for subsite1 cms author'
        );
    }

    public function testDuplicateSubsite()
    {
        // get subsite1 & create page
        $subsite1 = $this->objFromFixture(Subsite::class, 'domaintest1');
        $subsite1->activate();
        $page1 = new Page();
        $page1->Title = 'MyAwesomePage';
        $page1->write();
        $page1->publishRecursive();
        $this->assertEquals($page1->SubsiteID, $subsite1->ID);

        // duplicate
        $subsite2 = $subsite1->duplicate();
        $subsite2->activate();
        // change content on dupe
        $page2 = DataObject::get_one('Page', "\"Title\" = 'MyAwesomePage'");
        $page2->Title = 'MyNewAwesomePage';
        $page2->write();
        $page2->publishRecursive();

        // check change & check change has not affected subiste1
        $subsite1->activate();
        $this->assertEquals('MyAwesomePage', DataObject::get_by_id('Page', $page1->ID)->Title);
        $subsite2->activate();
        $this->assertEquals('MyNewAwesomePage', DataObject::get_by_id('Page', $page2->ID)->Title);
    }

    public function testDefaultPageCreatedWhenCreatingSubsite()
    {
        $subsite = new Subsite();
        $subsite->Title = 'New Subsite';
        $subsite->write();
        $subsite->activate();

        $pages = SiteTree::get();
        $this->assertGreaterThanOrEqual(1, $pages->count());
    }
}
