<?php
/**
 * webtrees: online genealogy
 * Copyright (C) 2019 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Fisharebest\Webtrees\Census;

/**
 * Test harness for the class CensusOfFrance1891
 */
class CensusOfFrance1891Test extends \Fisharebest\Webtrees\TestCase
{
    /**
     * Test the census place and date
     *
     * @covers \Fisharebest\Webtrees\Census\CensusOfFrance1891
     *
     * @return void
     */
    public function testPlaceAndDate(): void
    {
        $census = new CensusOfFrance1891();

        $this->assertSame('France', $census->censusPlace());
        $this->assertSame('15 JAN 1891', $census->censusDate());
    }

    /**
     * Test the census columns
     *
     * @covers \Fisharebest\Webtrees\Census\CensusOfFrance1891
     * @covers \Fisharebest\Webtrees\Census\AbstractCensusColumn
     *
     * @return void
     */
    public function testColumns(): void
    {
        $census  = new CensusOfFrance1891();
        $columns = $census->columns();

        $this->assertCount(6, $columns);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnSurname::class, $columns[0]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnGivenNames::class, $columns[1]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnAge::class, $columns[2]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnNationality::class, $columns[3]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnOccupation::class, $columns[4]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnRelationToHead::class, $columns[5]);

        $this->assertSame('Noms', $columns[0]->abbreviation());
        $this->assertSame('Prénoms', $columns[1]->abbreviation());
        $this->assertSame('Âge', $columns[2]->abbreviation());
        $this->assertSame('Nationalité', $columns[3]->abbreviation());
        $this->assertSame('Profession', $columns[4]->abbreviation());
        $this->assertSame('Position', $columns[5]->abbreviation());

        $this->assertSame('Noms de famille', $columns[0]->title());
        $this->assertSame('', $columns[1]->title());
        $this->assertSame('', $columns[2]->title());
        $this->assertSame('', $columns[3]->title());
        $this->assertSame('', $columns[4]->title());
        $this->assertSame('Position dans le ménage', $columns[5]->title());
    }
}
