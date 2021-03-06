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
 * Test harness for the class RegisterOfEngland1939
 */
class RegisterOfEngland1939Test extends \Fisharebest\Webtrees\TestCase
{
    /**
     * Test the census place and date
     *
     * @covers \Fisharebest\Webtrees\Census\RegisterOfEngland1939
     *
     * @return void
     */
    public function testPlaceAndDate(): void
    {
        $census = new RegisterOfEngland1939();

        $this->assertSame('England', $census->censusPlace());
        $this->assertSame('29 SEP 1939', $census->censusDate());
    }

    /**
     * Test the census columns
     *
     * @covers \Fisharebest\Webtrees\Census\RegisterOfEngland1939
     * @covers \Fisharebest\Webtrees\Census\AbstractCensusColumn
     *
     * @return void
     */
    public function testColumns(): void
    {
        $census  = new RegisterOfEngland1939();
        $columns = $census->columns();

        $this->assertCount(8, $columns);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnNull::class, $columns[0]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnNull::class, $columns[1]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnSurnameGivenNames::class, $columns[2]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnNull::class, $columns[3]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnSexMF::class, $columns[4]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnBirthDayMonthSlashYear::class, $columns[5]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnConditionEnglish::class, $columns[6]);
        $this->assertInstanceOf(\Fisharebest\Webtrees\Census\CensusColumnOccupation::class, $columns[7]);

        $this->assertSame('Schedule', $columns[0]->abbreviation());
        $this->assertSame('SubNum', $columns[1]->abbreviation());
        $this->assertSame('Name', $columns[2]->abbreviation());
        $this->assertSame('Role', $columns[3]->abbreviation());
        $this->assertSame('Sex', $columns[4]->abbreviation());
        $this->assertSame('DOB', $columns[5]->abbreviation());
        $this->assertSame('MC', $columns[6]->abbreviation());
        $this->assertSame('Occupation', $columns[7]->abbreviation());

        $this->assertSame('Schedule Number', $columns[0]->title());
        $this->assertSame('Schedule Sub Number', $columns[1]->title());
        $this->assertSame('Surname & other names', $columns[2]->title());
        $this->assertSame('For institutions only – for example, Officer, Visitor, Servant, Patient, Inmate', $columns[3]->title());
        $this->assertSame('Male or Female', $columns[4]->title());
        $this->assertSame('Date of birth', $columns[5]->title());
        $this->assertSame('Marital Condition - Married, Single, Unmarried, Widowed or Divorced', $columns[6]->title());
        $this->assertSame('Occupation', $columns[7]->title());
    }
}
