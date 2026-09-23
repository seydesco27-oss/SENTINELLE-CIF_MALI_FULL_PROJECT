<?php

namespace Tests\Unit;

use App\Services\SanctionsFileParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SanctionsFileParserTest extends TestCase
{
    public function test_xml_sources_keep_references_names_and_aliases(): void
    {
        $parser = new SanctionsFileParser();
        $un = $parser->parse('<CONSOLIDATED_LIST><INDIVIDUAL><DATAID>100</DATAID><FIRST_NAME>Amadou</FIRST_NAME><SECOND_NAME>Traoré</SECOND_NAME><INDIVIDUAL_ALIAS><ALIAS_NAME>AT</ALIAS_NAME></INDIVIDUAL_ALIAS></INDIVIDUAL></CONSOLIDATED_LIST>', 'ONU');
        $this->assertSame('AMADOU TRAORE', $un['entries'][0]['normalized']);
        $this->assertSame(['AT'], $un['entries'][0]['aliases']);
        $eu = $parser->parse('<export xmlns="urn:eu"><sanctionEntity euReferenceNumber="EU.100"><subjectType code="person"/><nameAlias wholeName="Ali Test"/><nameAlias wholeName="A Test"/></sanctionEntity></export>', 'EU');
        $this->assertSame('EU.100', $eu['entries'][0]['reference']);
        $this->assertSame('INDIVIDUAL', $eu['entries'][0]['type']);
        $ofac = $parser->parse('<sdnList xmlns="urn:ofac"><sdnEntry><uid>200</uid><firstName>Jane</firstName><lastName>Test</lastName><sdnType>Individual</sdnType><akaList><aka><uid>999</uid><lastName>Alias</lastName></aka></akaList></sdnEntry></sdnList>', 'OFAC');
        $this->assertSame('200', $ofac['entries'][0]['reference']);
        $this->assertSame('Jane Test', $ofac['entries'][0]['name']);
        $this->assertSame(['Alias'], $ofac['entries'][0]['aliases']);
    }

    public function test_csv_deduplicates_entities_and_preserves_aliases(): void
    {
        $parser = new SanctionsFileParser();
        $eu = $parser->parse("Entity_EU_ReferenceNumber;Entity_SubjectType;NameAlias_WholeName\nEU.1;person;Name One\nEU.1;person;Alias One\nEU.1;person;\n", 'EU');
        $this->assertSame(3, $eu['raw_count']);
        $this->assertCount(1, $eu['entries']);
        $this->assertSame(['Alias One'], $eu['entries'][0]['aliases']);
        $ofac = $parser->parse("123,\"TEST, Jane\",individual,SDGT\n", 'OFAC');
        $this->assertSame('TEST, Jane', $ofac['entries'][0]['name']);
    }

    public function test_txt_extracts_the_designation_and_alias(): void
    {
        $result = (new SanctionsFileParser())->parse('TEST, Jane (a.k.a. "J TEST"); DOB 01 Jan 1980; [SDGT]', 'OFAC');
        $this->assertSame('TEST, Jane', $result['entries'][0]['name']);
        $this->assertSame('INDIVIDUAL', $result['entries'][0]['type']);
        $this->assertSame(['J TEST'], $result['entries'][0]['aliases']);
    }

    public function test_external_entities_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SanctionsFileParser())->parse('<!DOCTYPE root [<!ENTITY ex SYSTEM "file:///secret">]><root>&ex;</root>', 'ONU');
    }

    public function test_wrong_source_and_html_are_rejected(): void
    {
        foreach (['<html>Sign in</html>', '<sdnList><sdnEntry><uid>1</uid><lastName>Test</lastName></sdnEntry></sdnList>', '<broken'] as $file) {
            try { (new SanctionsFileParser())->parse($file, 'ONU'); $this->fail('Invalid source was accepted'); }
            catch (InvalidArgumentException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
    }
}
