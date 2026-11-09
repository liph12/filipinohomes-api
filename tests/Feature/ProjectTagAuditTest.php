<?php

namespace Tests\Feature;

use App\Services\Project\ProjectTagAudit;
use Tests\TestCase;

/**
 * A listing whose title names a development it is not tagged to is flagged; one whose tag agrees with
 * its title is not; "near X" is a neighbour; a developer brand is not a building.
 */
class ProjectTagAuditTest extends TestCase
{
    private function index(): array
    {
        return ProjectTagAudit::buildIndex([
            ['id' => 1, 'name' => 'Horizon 101'],
            ['id' => 2, 'name' => 'Horizon 101 Tower 2'],
            ['id' => 3, 'name' => 'Avida Towers Riala'],
            ['id' => 4, 'name' => 'Avida Riala Tower 4'],
            ['id' => 5, 'name' => 'Grand Residences'],
            ['id' => 6, 'name' => 'Amani Grand Resort Residences'],
            ['id' => 7, 'name' => 'Citta di Mare'],
            ['id' => 8, 'name' => 'Amalfi'],
            ['id' => 9, 'name' => 'J Tower Residences'],
            ['id' => 10, 'name' => 'Jtower Residences'],
            ['id' => 11, 'name' => 'Deca'],
            ['id' => 12, 'name' => 'Deca Homes Tunghaan'],
            ['id' => 13, 'name' => 'Deca Homes Minglanilla'],
            ['id' => 14, 'name' => 'Deca Sentrio'],
            ['id' => 15, 'name' => 'Vista Residences Kamputhaw, Cebu City'],
            ['id' => 16, 'name' => 'For Sale: 1BR Condo in Subabasbas'],
            ['id' => 17, 'name' => 'The Residences'],
            ['id' => 18, 'name' => 'Corona Del Mar'],
        ]);
    }

    public function test_a_title_naming_an_untagged_development_is_flagged(): void
    {
        $i = $this->index();

        $this->assertSame(['project_id' => 18, 'where' => 'title', 'status' => 'not_tagged'], ProjectTagAudit::flag('Rush lot for sale in Corona Del Mar', '', null, $i));
        $this->assertSame(['project_id' => 1, 'where' => 'title', 'status' => 'not_tagged'], ProjectTagAudit::flag('Horizon 101 1 Bedroom for Rent', '', null, $i));
        $this->assertSame(2, ProjectTagAudit::flag('Studio in Horizon 101 Tower 2', '', null, $i)['project_id'], 'the longest name wins');
        $this->assertSame(['project_id' => 3, 'where' => 'property name', 'status' => 'not_tagged'], ProjectTagAudit::flag('1BR with balcony', 'Avida Towers Riala', null, $i));
        $this->assertNull(ProjectTagAudit::flag('2BR condo for sale in Cebu City', '', null, $i), 'no development named, nothing to flag');
    }

    public function test_a_tag_that_agrees_with_the_title_is_left_alone(): void
    {
        $i = $this->index();

        $this->assertNull(ProjectTagAudit::flag('Horizon 101 studio for sale', '', 1, $i));
        $this->assertNull(ProjectTagAudit::flag('AVIDA TOWER RIALA 1BR', '', 3, $i), 'tower and towers are one word');
        $this->assertNull(ProjectTagAudit::flag('Avida Riala Tower 4 studio', '', 3, $i), 'a tower of the development is the development');
        $this->assertNull(ProjectTagAudit::flag('Amalfi Citta de Mare 2 Bedroom Condo', '', 7, $i), 'most of the tagged name is in the title');
        $this->assertNull(ProjectTagAudit::flag('FOR ASSUME STUDIO AT JTOWER RESIDENCES', '', 9, $i), 'the same letters without the space');
        $this->assertNull(ProjectTagAudit::flag('Horizon 101 Tower 2 unit', '', 1, $i), 'tagged to the parent of the tower named');
    }

    public function test_a_tag_the_title_contradicts_is_a_mismatch(): void
    {
        $i = $this->index();

        $this->assertSame(['project_id' => 6, 'where' => 'title', 'status' => 'mismatch'], ProjectTagAudit::flag('AMANI GRAND RESORT RESIDENCES studio', '', 5, $i));
        $this->assertSame('mismatch', ProjectTagAudit::flag('Corona Del Mar lot', '', 1, $i)['status']);
    }

    public function test_near_a_development_is_not_in_it(): void
    {
        $i = $this->index();

        $this->assertNull(ProjectTagAudit::flag('Condo for rent near Horizon 101', '', null, $i));
        $this->assertNull(ProjectTagAudit::flag('Lot 5 minutes to Corona Del Mar', '', null, $i));
        $this->assertNull(ProjectTagAudit::flag('Studio beside Horizon 101', '', 3, $i), 'tagged elsewhere and only near this one');
        $this->assertSame(1, ProjectTagAudit::namedIn('Horizon 101 unit near Ayala', $i)['id'], '"near" after the name does not undo it');
    }

    public function test_brands_junk_and_generic_names_are_not_keys(): void
    {
        $i = $this->index();
        $keys = array_column($i['keys'], 1);

        $this->assertNotContains(11, $keys, '"Deca" starts three other names: a brand, not a building');
        $this->assertContains(12, $keys);
        $this->assertNotContains(16, $keys, 'a listing title typed into the projects table');
        $this->assertNotContains(17, $keys, 'all generic words');
        $this->assertContains(15, $keys);
        $this->assertSame(['project_id' => 12, 'where' => 'title', 'status' => 'not_tagged'], ProjectTagAudit::flag('House in Deca Homes Tunghaan', '', null, $i));
        $this->assertNull(ProjectTagAudit::flag('Deca house and lot', '', null, $i), 'the brand alone points nowhere');
        $this->assertSame(15, ProjectTagAudit::namedIn('2BR in Vista Residences Kamputhaw', $i)['id'], 'the city after the comma is not part of the name');
    }

    public function test_normalize_folds_case_punctuation_and_plurals(): void
    {
        $this->assertSame('avida tower riala cebu city', ProjectTagAudit::normalize('AVIDA TOWERS RIALA, Cebu City'));
        $this->assertSame('j tower residence', ProjectTagAudit::normalize("J-Tower Residence's"));
        $this->assertSame('casa and mira', ProjectTagAudit::normalize('Casa & Mira'));
    }
}
