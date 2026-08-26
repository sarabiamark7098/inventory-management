<?php

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Seeder;

/**
 * The DSWD Field Office XI organisational chart.
 *
 * Transcribed from the office register (Office.xlsx) so the server never needs
 * the spreadsheet. Ids are UUIDv5 derived from the source row id, which means
 * re-seeding — or seeding a second environment — produces the same keys and the
 * parent links keep resolving.
 *
 * `division_id` null means the office IS a division; set means it is a section
 * within that division. The source data is exactly two levels deep, and the
 * schema does not stop it going deeper if the organisation ever does.
 */
class OfficeSeeder extends Seeder
{
    public function run(): void
    {
        $offices = [
            ['id' => 'ac0c549f-47cd-5f1e-a117-46d9f6034ab9', 'code' => 'XI-FO-ALL', 'name' => 'All DSWD Field Office XI', 'division_id' => null],
            ['id' => '1afa9d08-ccf5-5b61-9f1a-ce5f72b1687e', 'code' => 'XI-FO-BAC', 'name' => 'FO Bids and Awards Committee', 'division_id' => 'a6ff1c58-bb73-5015-a763-e44c4348cf1a'],
            ['id' => '5c4abaac-ae0f-5df0-b374-5f862da4db2e', 'code' => 'XI-FO-COA', 'name' => 'FO Commision on Audit', 'division_id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc'],
            ['id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc', 'code' => 'XI-FO-ORD', 'name' => 'FO Office of the Regional Director', 'division_id' => null],
            ['id' => '513ecc44-5ede-590b-b649-6352f46234e5', 'code' => 'XI-FO-4PS-AS', 'name' => 'FO Pantawid - Administrative Section', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => '5ead8e56-58f8-5bff-a76b-84dc30735fec', 'code' => 'XI-FO-4PS-BDM', 'name' => 'FO Pantawid - Beneficiary Data Management Section', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30', 'code' => 'XI-FO-4PS-CBS', 'name' => 'FO Pantawid - Capability Building Division', 'division_id' => null],
            ['id' => '37641cb1-641e-561c-a485-73f6b3d9598b', 'code' => 'XI-FO-4PS-CVS', 'name' => 'FO Pantawid - Compliance Verification Section', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => '4d4b3450-fe4b-577d-84e7-a49370405b53', 'code' => 'XI-FO-4PS-FDS', 'name' => 'FO Pantawid - Family Development Section', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => '0e263eee-8124-5b28-9d22-a6f877404a3a', 'code' => 'XI-FO-4PS-GRS', 'name' => 'FO Pantawid - Grievance Redress Section', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => '4cbac3af-32a4-5e53-b577-437d70c15e72', 'code' => 'XI-FO-4PS-IPD', 'name' => 'FO Pantawid - Institutional Partnership Section', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => '0b3a5d55-48f3-5c5a-97ed-de1b695060b1', 'code' => 'XI-FO-4PS-MCCT', 'name' => 'FO Pantawid - Modified Conditional Cash Transfer Section', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => 'abdc5cae-0b17-5f92-ac35-efe98cb06a68', 'code' => 'XI-FO-4PS-OPS', 'name' => 'FO Pantawid - Operation', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => '7c555187-8d22-5b70-8d63-474c91bd0c45', 'code' => 'XI-FO-4PS-PMES', 'name' => 'FO Pantawid - Planning and Monitoring and Evaluation Section', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => 'd55f424e-16f8-5d02-9da4-5182a211d7f3', 'code' => 'XI-FO-4PS-POO-DC', 'name' => 'FO Pantawid Operations Office Davao City', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => 'ed453f69-a022-54ec-9c64-8b71bfa1b5da', 'code' => 'XI-FO-4PS-POO-DDN', 'name' => 'FO Pantawid Operations Office Davao del Norte', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => 'd9758437-12ab-54d4-ad93-ed6afe3f11d8', 'code' => 'XI-FO-4PS-POO-DDO', 'name' => 'FO Pantawid Operations Office Davao de Oro', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => '4a874ab8-ab9e-5853-8ec9-96390e3fa08c', 'code' => 'XI-FO-4PS-POO-DDS', 'name' => 'FO Pantawid Operations Office Davao del Sur', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => '5c69ed77-10f8-558f-b233-e53d0f80377a', 'code' => 'XI-FO-4PS-POO-DO', 'name' => 'FO Pantawid Operations Office Davao Oriental', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => 'd0acb3d6-326e-5041-8a5a-06bda8cc5af7', 'code' => 'XI-FO-4PS-POO-DOCC', 'name' => 'FO Pantawid Operations Office Davao Occidental', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => 'd0206cd9-b27a-5082-a16c-9cd65ec5a1ea', 'code' => 'XI-FO-4PS-RPMO', 'name' => 'FO Pantawid Regional Program Management Office', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => '4461916c-2e8f-5d37-9ec5-ab62f3542898', 'code' => 'XI-FO-4PS-SMU', 'name' => 'FO Pantawid - Social Marketing Unit', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => 'fb8bcbb9-b7b3-5559-8a97-ffc5d5dbe6b6', 'code' => 'XI-FO-4PS-SSDMS', 'name' => 'FO Pantawid - Social Services Delivery and Management Section', 'division_id' => 'b2b96ccb-e616-5155-a258-d9e1e2cfee30'],
            ['id' => 'a6ff1c58-bb73-5015-a763-e44c4348cf1a', 'code' => 'XI-FO-AD', 'name' => 'FO Administrative Division', 'division_id' => null],
            ['id' => '053595ff-2544-58f0-bc5d-f53f9a67203d', 'code' => 'XI-FO-AD-GSS', 'name' => 'FO General Service Section', 'division_id' => 'a6ff1c58-bb73-5015-a763-e44c4348cf1a'],
            ['id' => '77a87cb5-d4d2-5fa3-af94-d9ba71253693', 'code' => 'XI-FO-AD-PS', 'name' => 'FO Procurement Section', 'division_id' => 'a6ff1c58-bb73-5015-a763-e44c4348cf1a'],
            ['id' => 'a4d87ed8-7de1-5c75-9a0d-1e906071dd45', 'code' => 'XI-FO-AD-PSS', 'name' => 'FO Property and Supply Section', 'division_id' => 'a6ff1c58-bb73-5015-a763-e44c4348cf1a'],
            ['id' => 'ee457f61-c34e-598e-a76c-fbb39c2ea1a3', 'code' => 'XI-FO-AD-RAMS', 'name' => 'FO Records Management Section', 'division_id' => 'a6ff1c58-bb73-5015-a763-e44c4348cf1a'],
            ['id' => '3efd1e01-298e-5d2f-8f70-b0ce866cf494', 'code' => 'XI-FO-CBS-MTA', 'name' => 'FO Minors Travelling Abroad Office', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '4d784989-5d7e-5aef-9b1d-5f4463a2d861', 'code' => 'XI-FO-CBSS-AH', 'name' => 'FO CBSS Angels Haven', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '86a555b5-3351-54f9-81d5-cee82fb3d273', 'code' => 'XI-FO-CBSS-ANVRC', 'name' => 'FO A/NVRC Island Cluster', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '3389c07e-7519-5c7c-aaa3-dd40097f78fc', 'code' => 'XI-FO-CBSS-HA', 'name' => 'FO CBSS Home for the Aged', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '5cf9a1a2-0cba-5180-8c0f-377114dacd7c', 'code' => 'XI-FO-CBSS-RHGW', 'name' => 'FO Regional Home for Girls and Women', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '3b143498-0712-5e3e-a4a1-8a937ab2eaed', 'code' => 'XI-FO-CBSS-RSCC', 'name' => 'FO Reception and Study Center for Children', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '62e03128-46dd-526d-9fe9-8c5d3c05618c', 'code' => 'XI-FO-CBSS-RRCY', 'name' => 'FO Regional Rehabilitation Center for Youth', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => 'b4dcd3a9-1e24-5857-a2fa-f9a8edf8e206', 'code' => 'XI-FO-ORD-RACCO', 'name' => 'FO Regional Alternative Child Care Office', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => 'f795eb97-ab0b-53ea-85ea-a29faa78e61b', 'code' => 'XI-FO-COMBS-SFP', 'name' => 'FO Supplementary Feeding Program', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '95e5efe3-b496-5282-9125-835bf1aecc16', 'code' => 'XI-FO-COMBS-SPO', 'name' => 'FO Social Pension Office', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '42f4c419-1846-554c-88b8-36f7ea5b4715', 'code' => 'XI-FO-DRMD', 'name' => 'FO Disaster Response Management Division', 'division_id' => null],
            ['id' => '6e445a76-98cf-561f-bfed-576ec063815a', 'code' => 'XI-FO-DRMD-DRIMS', 'name' => 'FO Regional Response & Information Management Section', 'division_id' => '42f4c419-1846-554c-88b8-36f7ea5b4715'],
            ['id' => 'e9ea5a66-a42b-536d-83d7-2d577a84dfb0', 'code' => 'XI-FO-DRMD-DRRMS', 'name' => 'FO Disaster Response Rehabilitation Managment Section', 'division_id' => '42f4c419-1846-554c-88b8-36f7ea5b4715'],
            ['id' => '3f0e91ff-ea67-5b3f-8dcf-42cd35df4f45', 'code' => 'XI-FO-DRMD-RROS', 'name' => 'FO Regional Resource Operation Section', 'division_id' => '42f4c419-1846-554c-88b8-36f7ea5b4715'],
            ['id' => '7829b0e4-3144-5c45-9721-c9a5a7f742ac', 'code' => 'XI-FO-FMD', 'name' => 'FO Financial Management Division', 'division_id' => null],
            ['id' => '2f526922-9683-5527-a1a7-78ce70b4f13b', 'code' => 'XI-FO-FMD-AS', 'name' => 'FO Accounting Section', 'division_id' => '7829b0e4-3144-5c45-9721-c9a5a7f742ac'],
            ['id' => '48e44058-fadf-53cd-b901-f445d4c22a49', 'code' => 'XI-FO-FMD-BS', 'name' => 'FO Budget Section', 'division_id' => '7829b0e4-3144-5c45-9721-c9a5a7f742ac'],
            ['id' => '8fdef9ab-a434-55f9-9b0e-7521d4379ac2', 'code' => 'XI-FO-FMD-CS', 'name' => 'FO Cash Section', 'division_id' => '7829b0e4-3144-5c45-9721-c9a5a7f742ac'],
            ['id' => '92510bee-2f98-5358-9cef-844bc031d3ed', 'code' => 'XI-FO-HRMDD', 'name' => 'FO Human Resource Management and Development Division', 'division_id' => null],
            ['id' => '1e7aea3b-b9b5-5af1-bd2e-2ebc20997764', 'code' => 'XI-FO-HRMDD-HRPPMS', 'name' => 'FO Human Resource Planning and Performance Management Section', 'division_id' => '92510bee-2f98-5358-9cef-844bc031d3ed'],
            ['id' => 'e8a242bb-6699-53ec-a928-42b26f2ad76e', 'code' => 'XI-FO-HRMDD-HRWS', 'name' => 'FO Human Resource Welfare Section', 'division_id' => '92510bee-2f98-5358-9cef-844bc031d3ed'],
            ['id' => '54069f35-1b34-51ac-9f7c-e262a926a244', 'code' => 'XI-FO-HRMDD-LDS', 'name' => 'FO Learning and Development Section', 'division_id' => '92510bee-2f98-5358-9cef-844bc031d3ed'],
            ['id' => '15cb9ab9-9e09-5b77-8013-582de8568672', 'code' => 'XI-FO-HRMDD-PAS', 'name' => 'FO Personal Administration Section', 'division_id' => '92510bee-2f98-5358-9cef-844bc031d3ed'],
            ['id' => 'f5c93b80-1e14-57f3-ace6-adff2336e13a', 'code' => 'XI-FO-IAU', 'name' => 'FO Internal Audit Unit', 'division_id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc'],
            ['id' => 'ae2e2ba0-08c9-5d04-b695-e00103fd340a', 'code' => 'XI-FO-KC', 'name' => 'FO Kalhi-CIDSS PMO', 'division_id' => '80e538e1-4416-5a0d-8cc7-6f3288d44b0b'],
            ['id' => '13980d0a-d7e0-528d-9b4d-1cb6672d401d', 'code' => 'XI-FO-LU', 'name' => 'FO Legal Unit', 'division_id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc'],
            ['id' => 'd25099e8-e527-5c0a-aa8a-91ca1704f510', 'code' => 'XI-FO-OARDA', 'name' => 'FO Office of Assistant Regional Director for Administration', 'division_id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc'],
            ['id' => '0f4a478a-196d-5fee-b886-a752278ac018', 'code' => 'XI-FO-OARDO', 'name' => 'FO Office of Assistant Regional Director for Operation', 'division_id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc'],
            ['id' => '79165fc5-df7c-5991-99e9-ca1f8b67ee65', 'code' => 'XI-FO-ORD-STU', 'name' => 'FO Social Technology Unit', 'division_id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc'],
            ['id' => '56fe1baf-5dee-5744-a900-feef06891b24', 'code' => 'XI-FO-PPD', 'name' => 'FO Policy and Plans Division', 'division_id' => null],
            ['id' => 'eff736c5-f88a-5a7c-beec-9fb80aa60f57', 'code' => 'XI-FO-PPD-ICT', 'name' => 'FO Information & Planning Communication Technology Section', 'division_id' => '56fe1baf-5dee-5744-a900-feef06891b24'],
            ['id' => '3cf6ab87-97b8-54a1-88a6-0f39a571f82e', 'code' => 'XI-FO-PPD-NHTS', 'name' => 'FO National Household Targetting Section', 'division_id' => '56fe1baf-5dee-5744-a900-feef06891b24'],
            ['id' => '53256fb3-d26e-554f-9988-7b56e4bd306e', 'code' => 'XI-FO-PPD-PDPS', 'name' => 'FO Policy Development and Planning Section', 'division_id' => '56fe1baf-5dee-5744-a900-feef06891b24'],
            ['id' => '853385fb-a3b3-56e0-8803-deb943d9be15', 'code' => 'XI-FO-PPD-SS', 'name' => 'FO Standard Section', 'division_id' => '56fe1baf-5dee-5744-a900-feef06891b24'],
            ['id' => 'bdf6c63c-a141-5233-8781-f9793f744028', 'code' => 'XI-FO-PPD-UCT', 'name' => 'FO Unconditional Cash Transfer', 'division_id' => '56fe1baf-5dee-5744-a900-feef06891b24'],
            ['id' => '80e538e1-4416-5a0d-8cc7-6f3288d44b0b', 'code' => 'XI-FO-PROMSD', 'name' => 'FO Promotive Service Division', 'division_id' => null],
            ['id' => '64d0b78d-24f2-5d76-a68b-37db9ffdcd43', 'code' => 'XI-FO-PROMSD-EPAHP', 'name' => 'FO Enhance Partnership Against Hunger and Poverty', 'division_id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc'],
            ['id' => 'aa40c6b3-b80d-5f52-9fe6-2acf7ea87d6f', 'code' => 'XI-FO-PROMSD-SLP', 'name' => 'FO Sustainable Livelihood Program PMO', 'division_id' => '80e538e1-4416-5a0d-8cc7-6f3288d44b0b'],
            ['id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606', 'code' => 'XI-FO-PSD', 'name' => 'FO Protective Service Division', 'division_id' => null],
            ['id' => 'c5c37974-d71c-5fcf-bfb2-78610b518390', 'code' => 'XI-FO-PSD-CBS', 'name' => 'FO Capacity Building Section', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '26665023-39be-5263-b08b-1b9309e52017', 'code' => 'XI-FO-PSD-CBSS', 'name' => 'FO Center Based Services Section', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '0ab569e1-7107-53c9-a265-fb67143a3f94', 'code' => 'XI-FO-PSD-CIS', 'name' => 'FO Crisis Intervention Section', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => 'cad98757-abfd-5c45-88ef-3a5c7122711a', 'code' => 'XI-FO-PSD-COMBS', 'name' => 'FO Community Based Services Section', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => 'e44ba128-7566-5b6b-af13-964b15f9ea78', 'code' => 'XI-FO-SMU', 'name' => 'FO Social Marketing Unit', 'division_id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc'],
            ['id' => '5705adb0-eed1-5425-81f6-b83b0569e849', 'code' => 'XI-FO-SWAD-DC', 'name' => 'FO Social Welfare and Development Office- Davao City Third District', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '8e65a45e-d8fa-5270-95a1-15c2bb4850b0', 'code' => 'XI-FO-SWAD-DDN', 'name' => 'FO Social Welfare and Development Office- Davao del Norte', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '11e3def5-02b1-55da-91da-7b1535fcbec9', 'code' => 'XI-FO-SWAD-DDO', 'name' => 'FO Social Welfare and Development Office- Davao de Oro', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '60de6486-61ef-5abd-adda-9d870dd1c826', 'code' => 'XI-FO-SWAD-DDS', 'name' => 'FO Social Welfare and Development Office- Davao del Sur', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '9b8b1a58-f493-53eb-98ad-96871878d0f4', 'code' => 'XI-FO-SWAD-DO', 'name' => 'FO Social Welfare and Development Office- Davao Oriental', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '2fe74fae-b8c3-5d02-92d8-e6605b7b2e81', 'code' => 'XI-FO-SWAD-DOCC', 'name' => 'FO Social Welfare and Development Office- Davao Occidental', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '0e973116-d834-5eca-ace1-70b8c855ec27', 'code' => 'XI-FO-RJJWC', 'name' => 'FO - Regional Juvenile Justice and Welfare Council', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '150ad780-ecd6-5693-bd08-cff07764e2e0', 'code' => 'XI-FO-RSCWC', 'name' => 'FO - Regional Sub-Committee for the Welfare of Children', 'division_id' => 'b79410f6-a990-5b8b-9e3e-e248114b7606'],
            ['id' => '19b9a52e-252c-55f7-81bb-6de7be3c0802', 'code' => 'XI-FO-ORD-TAAORSS', 'name' => 'FO Technical/Advisor Assistance and other related Support Services', 'division_id' => 'a463f3f1-c979-5bed-82c1-9cbaf4ad7ccc'],
        ];

        // Divisions first: a section's foreign key cannot point at a row that
        // does not exist yet.
        foreach ([true, false] as $divisionsPass) {
            foreach ($offices as $office) {
                if (($office['division_id'] === null) !== $divisionsPass) {
                    continue;
                }
                Office::updateOrCreate(['id' => $office['id']], $office);
            }
        }

        $this->command?->info(sprintf(
            'Seeded %d offices (%d divisions, %d sections).',
            count($offices),
            count(array_filter($offices, fn ($o) => $o['division_id'] === null)),
            count(array_filter($offices, fn ($o) => $o['division_id'] !== null)),
        ));
    }
}
