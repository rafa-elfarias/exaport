<?php
// This file is part of Exabis Eportfolio (extension for Moodle)
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
// (c) 2016 GTN - Global Training Network GmbH <office@gtn-solutions.com>.

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Exception;

require_once(__DIR__.'/inc.php');
require_once(__DIR__.'/blockmediafunc.php');

$access = optional_param('access', 0, PARAM_TEXT);

require_login(0, true);

// main content:
$general_content = '';

$url = '/blocks/exaport/shared_view.php';
$PAGE->set_url($url);
$context = context_system::instance();
$PAGE->set_context($context);

if (!$view = block_exaport_get_view_from_access($access)) {
    print_error("viewnotfound", "block_exaport");
}

$is_pdf = optional_param('ispdf', 0, PARAM_INT);

$conditions = array("id" => $view->userid);
if (!$user = $DB->get_record("user", $conditions)) {
    print_error("nouserforid", "block_exaport");
}

$portfoliouser = block_exaport_get_user_preferences($user->id);

// Read blocks.
$query = "select b.*". // , i.*, i.id as itemid".
        " FROM {block_exaportviewblock} b".
        " WHERE b.viewid = ? ORDER BY b.positionx, b.positiony";

$blocks = $DB->get_records_sql($query, array($view->id));

$badges = block_exaport_get_all_user_badges($view->userid);

// Read columns.
$columns = array();
foreach ($blocks as $block) {
    if (!isset($columns[$block->positionx])) {
        $columns[$block->positionx] = array();
    }

    if ($block->type == 'item') {
        $conditions = array("id" => $block->itemid);
        if ($item = $DB->get_record("block_exaportitem", $conditions)) {
            if (!$block->width) {
                $block->width = 320;
            }
            if (!$block->height) {
                $block->height = 240;
            }
            $item->intro = process_media_url($item->intro, $block->width, $block->height);
            // Add checking on sharable item.
            if ($sharable = block_exaport_can_user_access_shared_item($view->userid, $item->id) || $view->userid == $item->userid) {
                $block->item = $item;
            } else {
                continue; // Hide unshared items.
            }
        } else {
            $block->type = 'text';
        }
    }
    $columns[$block->positionx][] = $block;
}

block_exaport_init_js_css();

if (!$is_pdf) {
    if ($view->access->request == 'intern') {
        block_exaport_print_header("shared_views");
    } else {
        $PAGE->requires->css('/blocks/exaport/css/shared_view.css');
        $PAGE->set_title(get_string("externaccess", "block_exaport"));
        $PAGE->set_heading(get_string("externaccess", "block_exaport")." ".fullname($user, $user->id));

        $general_content .= $OUTPUT->header();
        $general_content .= block_exaport_wrapperdivstart();
    }
}

if (!$is_pdf) {
    ?>
    <script type="text/javascript">
        //<![CDATA[
        jQueryExaport(function ($) {
            $('.view-item').click(function (event) {
                if ($(event.target).is('a')) {
                    // ignore if link was clicked
                    return;
                }

                var link = $(this).find('.view-item-link a');
                if (link.length)
                    document.location.href = link.attr('href');
            });
        });
        //]]>
    </script>
    <?php
}

$comp = block_exaport_check_competence_interaction();

require_once(__DIR__.'/lib/resumelib.php');
$resume = block_exaport_get_resume_params($view->userid, true);

$colslayout = array(
        "1" => 1, "2" => 2, "3" => 2, "4" => 2, "5" => 3, "6" => 3, "7" => 3, "8" => 4, "9" => 4, "10" => 5,
);
if (!isset($view->layout) || $view->layout == 0) {
    $view->layout = 2;
}
$general_content .= '<div id="view">';
$general_content .= '<table class="table_layout layout'.$view->layout.'""><tr>';
$data_for_pdf = array(); // for old pdf view
$data_for_pdf_blocks = array(); // for new pdf view
for ($i = 1; $i <= $colslayout[$view->layout]; $i++) {
    $data_for_pdf[$i] = array();
    $data_for_pdf_blocks[$i] = array();
    $general_content .= '<td class="view-column td'.$i.'">';
    if (isset($columns[$i])) {
        foreach ($columns[$i] as $block) {
            $blockForPdf = '<div class="view-block">';
            if ($block->text) {
                $block->text = file_rewrite_pluginfile_urls($block->text, 'pluginfile.php', context_user::instance($USER->id)->id,
                        'block_exaport', 'view_content', $access);
                $block->text = format_text($block->text, FORMAT_HTML);
            }
            $attachments = array();
            switch ($block->type) {
                case 'item':
                    $item = $block->item;
                    $competencies = null;

                    if ($comp) {
                        $comps = block_exaport_get_active_comps_for_item($item);
                        if ($comps && is_array($comps) && array_key_exists('descriptors', $comps)) {
                            $competencies = $comps['descriptors'];
                        } else {
                            $competencies = null;
                        }

                        if ($competencies) {
                            $competenciesoutput = "";
                            foreach ($competencies as $competence) {
                                $competenciesoutput .= $competence->title.'<br>';
                            }

                            // TODO: still needed?
                            $competenciesoutput = str_replace("\r", "", $competenciesoutput);
                            $competenciesoutput = str_replace("\n", "", $competenciesoutput);
                            $competenciesoutput = str_replace("\"", "&quot;", $competenciesoutput);
                            $competenciesoutput = str_replace("'", "&prime;", $competenciesoutput);

                            $item->competences = $competenciesoutput;
                        }

                    }

                    $href = 'shared_item.php?access=view/'.$access.'&itemid='.$item->id.'&att='.$item->attachment;

                    $general_content .= '<div class="view-item view-item-type-'.$item->type.'">';
                    // Thumbnail of item.
                    $fileparams = '';
                    if ($item->type == "file") {
                        $select = "contextid='".context_user::instance($item->userid)->id."' ".
                                " AND component='block_exaport' AND filearea='item_file' AND itemid='".$item->id."' AND filesize>0 ";
                        if ($files = $DB->get_records_select('files', $select, null, 'id, filename, mimetype, filesize')) {
                            if (is_array($files)) {
                                $width = '';
                                if (count($files) > 5) {
                                    $width = 's35';
                                } else if (count($files) > 3) {
                                    $width = 's40';
                                } else if (count($files) > 2) {
                                    $width = 's50';
                                } else if (count($files) > 1) {
                                    $width = 's75';
                                }

                                foreach ($files as $file) {
                                    if (strpos($file->mimetype, "image") !== false) {
                                        $imgsrc = $CFG->wwwroot."/pluginfile.php/".context_user::instance($item->userid)->id.
                                                "/".'block_exaport'."/".'item_file'."/view/".$access."/itemid/".$item->id."/".
                                                $file->filename;
                                        $general_content .= '<div class="view-item-image"><img src="'.$imgsrc.'" class="'.$width.'" alt=""/></div>';
                                        if ($is_pdf) {
                                            $imgsrc .= '/forPdf/'.$view->hash.'/'.$view->id.'/'.$USER->id;
                                        }
                                        $blockForPdf .= '<div class="view-item-image">
                                                            <img align = "right"
                                                                border = "0"
                                                                src = "'.$imgsrc.'"
                                                                width = "'.((int)filter_var($width, FILTER_SANITIZE_NUMBER_INT) ?: '100').'"
                                                                alt = "" />
                                                         </div>';
                                    } else {
                                        // Link to file.
                                        $ffurl = s("{$CFG->wwwroot}/blocks/exaport/portfoliofile.php?access=view/".$access.
                                                "&itemid=".$item->id."&inst=".$file->pathnamehash);
                                        // Human filesize.
                                        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
                                        $power = $file->filesize > 0 ? floor(log($file->filesize, 1024)) : 0;
                                        $filesize = number_format($file->filesize / pow(1024, $power), 2, '.', ',').' '.$units[$power];
                                        // Fileinfo block.
                                        $fileparams = '<div class="view-item-file"><a href="'.$ffurl.'" >'.$file->filename.'</a> '.
                                                '<span class="filedescription">('.$filesize.')</span></div>';
                                        if (block_exaport_is_valid_media_by_filename($file->filename)) {
                                            $general_content .= '<div class="view-item-image"><img height="60" src="'.$CFG->wwwroot.
                                                    '/blocks/exaport/pix/media.png" alt=""/></div>';
                                            $blockForPdf .= '<img height="60" src="'.$CFG->wwwroot. '/blocks/exaport/pix/media.png" align="right" />';
                                        }
                                    };
                                }
                            }
                        };
                    } else if ($item->type == "link") {
                        $general_content .= '<div class="picture" style="float:right; position: relative; height: 100px; width: 100px;"><a href="'.
                                $href.'"><img style="max-width: 100%; max-height: 100%;" src="'.$CFG->wwwroot.
                                '/blocks/exaport/item_thumb.php?item_id='.$item->id.'&access='.$access.'" alt=""/></a></div>';
                        $blockForPdf .= '<img align="right"
                                                style="" height="100"
                                                src="'.$CFG->wwwroot.'/blocks/exaport/item_thumb.php?item_id='.$item->id.'&access='.$access.'&ispdf=1&vhash='.$view->hash.'&vid='.$view->id.'&uid='.$USER->id.'"
                                                alt="" />';
                    };
                    $general_content .= '<div class="view-item-header" title="'.$item->type.'">'.$item->name;
                    // Falls Interaktion ePortfolio - competences aktiv und User ist Lehrer.
                    if ($comp && has_capability('block/exaport:competences', $context)) {
                        if ($competencies) {
                            $general_content .= '<img align="right" src="'.$CFG->wwwroot.
                                    '/blocks/exaport/pix/application_view_tile.png" alt="competences"/>';
                        }
                    }
                    $general_content .= '</div>';
                    $blockForPdf .= '<h4>'.$item->name.'</h4>';
                    $general_content .= $fileparams;
                    $blockForPdf .= $fileparams;
                    $intro = file_rewrite_pluginfile_urls($item->intro, 'pluginfile.php', context_user::instance($item->userid)->id,
                            'block_exaport', 'item_content', 'view/'.$access.'/itemid/'.$item->id);
                    $intro = format_text($intro, FORMAT_HTML, ['noclean' => true]);
                    $general_content .= '<div class="view-item-text">';
                    $blockForPdf .= '<div class="view-item-text">';
                    if ($item->url && $item->url != "false") {
                        // Link.
                        $general_content .= '<a href="'.s($item->url).'" target="_blank">'.str_replace('http://', '', $item->url).'</a><br />';
                        $blockForPdf .= '<a href="'.s($item->url).'" target="_blank">'.str_replace('http://', '', $item->url).'</a><br />';
                    }
                    $general_content .= $intro.'</div>';
                    $blockForPdf .= $intro.'</div>';
                    if ($competencies) {
                        $general_content .= '<div class="view-item-competences">'.
                                '<script type="text/javascript" src="javascript/wz_tooltip.js"></script>'.
                                '<a onmouseover="Tip(\''.$item->competences.'\')" onmouseout="UnTip()">'.
                                '<img src="'.$CFG->wwwroot.'/blocks/exaport/pix/comp.png" class="iconsmall" alt="'.'competences'.'" />'.
                                '</a></div>';
                    }
                    $general_content .= '<div class="view-item-link"><a href="'.s($href).'">'.block_exaport_get_string('show').'</a></div>';
                    $general_content .= '</div>';
                    break;
                case 'personal_information':
                    $general_content .= '<div class="header">'.$block->block_title.'</div>';
                    if ($block->block_title) {
                        $blockForPdf .= '<h4>'.$block->block_title.'</h4>';
                    }
                    $general_content .= '<div class="view-personal-information">';
                    $blockForPdf .= '<div class="view-personal-information">';
                    if (isset($block->picture)) {
                        $general_content .= '<div class="picture" style="float:right; position: relative;"><img src="'.$block->picture.
                                '" alt=""/></div>';
                        $blockForPdf .= '<img src="'.$block->picture.'" align="right" />';
                    }
                    $person_info = '';
                    if (isset($block->firstname) or isset($block->lastname)) {
                        $person_info .= '<div class="name">';
                        if (isset($block->firstname)) {
                            $person_info .= $block->firstname;
                        }
                        if (isset($block->lastname)) {
                            $person_info .= ' '.$block->lastname;
                        }
                        $person_info .= '</div>';
                    };
                    if (isset($block->email)) {
                        $person_info .= '<div class="email">'.$block->email.'</div>';
                    }
                    if (isset($block->text)) {
                        $person_info .= '<div class="body">'.$block->text.'</div>';
                    }
                    $general_content .= $person_info;
                    $general_content .= '</div>';
                    $blockForPdf .= $person_info;
                    $blockForPdf .= '</div>';
                    break;
                case 'headline':
                    $general_content .= '<div class="header view-header">'.nl2br($block->text).'</div>';
                    $blockForPdf .= '<h4>'.nl2br($block->text).'</h4>';
                    break;
                case 'media':
                    $general_content .= '<div class="header view-header">'.nl2br($block->block_title).'</div>';
                    if ($block->block_title) {
                        $blockForPdf .= '<h4>'.nl2br($block->block_title).'</h4>';
                    }
                    $general_content .= '<div class="view-media">';
                    if (!empty($block->contentmedia)) {
                        $general_content .= $block->contentmedia;
                    }
                    $general_content .= '</div>';
                    $blockForPdf .= '<p><i>----media----</i></p>';
                    // $blockForPdf .= '</div>';
                    break;
                case 'badge':
                    if (count($badges) == 0) {
                        continue 2;
                    }
                    $badge = null;
                    foreach ($badges as $tmp) {
                        if ($tmp->id == $block->itemid) {
                            $badge = $tmp;
                            break;
                        };
                    };
                    if (!$badge) {
                        // Badge not found.
                        continue 2;
                    }
                    $general_content .= '<div class="header">'.nl2br($badge->name).'</div>';
                    $blockForPdf .= '<h4>'.nl2br($badge->name).'</h4>';
                    $general_content .= '<div class="view-text">';
                    $general_content .= '<div style="float:right; position: relative; height: 100px; width: 100px;" class="picture">';
                    if (!$badge->courseid) { // For badges with courseid = NULL.
                        $badge->imageUrl = (string) moodle_url::make_pluginfile_url(1, 'badges', 'badgeimage',
                                                                                    $badge->id, '/', 'f1', false);
                    } else {
                        $context = context_course::instance($badge->courseid);
                        $badge->imageUrl = (string) moodle_url::make_pluginfile_url($context->id, 'badges', 'badgeimage',
                                                                                    $badge->id, '/', 'f1', false);
                    }
                    $general_content .= '<img src="'.$badge->imageUrl.'" />';
                    $general_content .= '</div>';
                    $general_content .= '<div class="badge-description">';
                    $general_content .= format_text($badge->description, FORMAT_HTML);
                    $general_content .= '</div>';
                    $general_content .= '</div>';
                    $blockForPdf .= '<p>'.format_text($badge->description, FORMAT_HTML).'</p>';
                    $blockForPdf .= '<img align="right" src="'.$badge->imageUrl.'" />';
                    // $blockForPdf .= '</div>';
                    break;
                case 'cv_information':
                    $body_content = '';
                    switch ($block->resume_itemtype) {
                        case 'edu':
                            if ($block->itemid && $resume && $resume->educations[$block->itemid]) {
                                $item_data = $resume->educations[$block->itemid];
                                $attachments = $item_data->attachments;
                                $description = '';
                                $description .= '<span class="edu_institution">'.$item_data->institution.':</span> ';
                                $description .= '<span class="edu_qualname">'.$item_data->qualname.'</span>';
                                if ($item_data->startdate != '' || $item_data->enddate != '') {
                                    $description .= ' (';
                                    if ($item_data->startdate != '') {
                                        $description .= '<span class="edu_startdate">'.$item_data->startdate.'</span>';
                                    }
                                    if ($item_data->enddate != '') {
                                        $description .= '<span class="edu_enddate"> - '.$item_data->enddate.'</span>';
                                    }
                                    $description .= ')';
                                }
                                if ($item_data->qualdescription != '') {
                                    $description .= '<span class="edu_qualdescription">'.$item_data->qualdescription.'</span>';
                                }
                                $body_content .= $description;
                            }
                            break;
                        case 'employ':
                            if ($block->itemid && $resume && $resume->employments[$block->itemid]) {
                                $item_data = $resume->employments[$block->itemid];
                                $attachments = $item_data->attachments;
                                $description = '';
                                $description .= '<span class="employ_jobtitle">'.$item_data->jobtitle.':</span> ';
                                $description .= '<span class="employ_employer">'.$item_data->employer.'</span>';
                                if ($item_data->startdate != '' || $item_data->enddate != '') {
                                    $description .= ' (';
                                    if ($item_data->startdate != '') {
                                        $description .= '<span class="employ_startdate">'.$item_data->startdate.'</span>';
                                    }
                                    if ($item_data->enddate != '') {
                                        $description .= '<span class="employ_enddate"> - '.$item_data->enddate.'</span>';
                                    }
                                    $description .= ')';
                                }
                                if ($item_data->positiondescription != '') {
                                    $description .= '<span class="employ_positiondescription">'.$item_data->positiondescription.'</span>';
                                }
                                $body_content .= $description;
                            }
                            break;
                        case 'certif':
                            if ($block->itemid && $resume && $resume->certifications[$block->itemid]) {
                                $item_data = $resume->certifications[$block->itemid];
                                $attachments = $item_data->attachments;
                                $description = '';
                                $description .= '<span class="certif_title">'.$item_data->title.'</span> ';
                                if ($item_data->date != '') {
                                    $description .= '<span class="certif_date">('.$item_data->date.')</span>';
                                }
                                if ($item_data->description != '') {
                                    $description .= '<span class="certif_description">'.$item_data->description.'</span>';
                                }
                                $body_content = $description;
                            }
                            break;
                        case 'public':
                            if ($block->itemid && $resume && $resume->publications[$block->itemid]) {
                                $item_data = $resume->publications[$block->itemid];
                                $attachments = $item_data->attachments;
                                $description = '';
                                $description .= '<span class="public_title">'.$item_data->title;
                                if ($item_data->contribution != '') {
                                    $description .= ' ('.$item_data->contribution.')';
                                }
                                $description .= '</span> ';
                                if ($item_data->date != '') {
                                    $description .= '<span class="public_date">('.$item_data->date.')</span>';
                                }
                                if ($item_data->contributiondetails != '' || $item_data->url != '') {
                                    $description .= '<span class="public_description">';
                                    if ($item_data->contributiondetails != '') {
                                        $description .= $item_data->contributiondetails;
                                    }
                                    if ($item_data->url != '') {
                                        $description .= '<br /><a href="'.$item_data->url.'" class="public_url" target="_blank">'.$item_data->url.'</a>';
                                    }
                                    $description .= '</span>';
                                }
                                $body_content = $description;
                            }
                            break;
                        case 'mbrship':
                            if ($block->itemid && $resume && $resume->profmembershipments[$block->itemid]) {
                                $item_data = $resume->profmembershipments[$block->itemid];
                                $attachments = $item_data->attachments;
                                $description = '';
                                $description .= '<span class="mbrship_title">'.$item_data->title.'</span> ';
                                if ($item_data->startdate != '' || $item_data->enddate != '') {
                                    $description .= ' (';
                                    if ($item_data->startdate != '') {
                                        $description .= '<span class="mbrship_startdate">'.$item_data->startdate.'</span>';
                                    }
                                    if ($item_data->enddate != '') {
                                        $description .= '<span class="mbrship_enddate"> - '.$item_data->enddate.'</span>';
                                    }
                                    $description .= ')';
                                }
                                if ($item_data->description != '') {
                                    $description .= '<span class="mbrship_description">'.$item_data->description.'</span>';
                                }
                                $body_content = $description;
                            }
                            break;
                        case 'goalspersonal':
                        case 'goalsacademic':
                        case 'goalscareers':
                        case 'skillspersonal':
                        case 'skillsacademic':
                        case 'skillscareers':
                            $attachments = @$resume->{$block->resume_itemtype.'_attachments'};
                            $description = '';
                            if ($resume && $resume->{$block->resume_itemtype}) {
                                $description .= '<span class="'.$block->resume_itemtype.'_text">'.$resume->{$block->resume_itemtype}.'</span> ';
                            }
                            $body_content = $description;
                            break;
                        case 'interests':
                            $description = '';
                            if ($resume->interests != '') {
                                $description .= '<span class="interests">'.$resume->interests.'</span> ';
                            }
                            $body_content = $description;
                            break;
                        default:
                            $general_content .= '!!! '.$block->resume_itemtype.' !!!';
                    }

                    if ($attachments && is_array($attachments) && count($attachments) > 0 && $block->resume_withfiles) {
                        $body_content .= '<ul class="resume_attachments '.$block->resume_itemtype.'_attachments">';
                        foreach ($attachments as $attachm) {
                            $body_content .= '<li><a href="'.$attachm['fileurl'].'" target="_blank">'.$attachm['filename'].'</a></li>';
                        }
                        $body_content .= '</ul>';
                    }

                    // if the resume item is empty - do not show
                    if ($body_content != '') {
                        $general_content .= '<div class="view-cv-information">';
                        /*if (isset($block->picture)) {
                            echo '<div class="picture" style="float:right; position: relative;"><img src="'.$block->picture.
                                    '" alt=""/></div>';
                        }*/
                        $general_content .= $body_content;
                        $general_content .= '</div>';
                        $blockForPdf .= $body_content;
                    }
                    break;
                default:
                    // Text.
                    $general_content .= '<div class="header">'.$block->block_title.'</div>';
                    $general_content .= '<div class="view-text">';
                    $general_content .= format_text($block->text, FORMAT_HTML);
                    $general_content .= '</div>';
                    if ($block->block_title) {
                        $blockForPdf .= "\r\n".'<h4>'.$block->block_title.'</h4>';
                    }
                    $pdf_text = format_text($block->text, FORMAT_HTML);
                    // If the text has HTML <img> - it can broke view template. Try to clean it
                   /* try {*/
                        $dom = new DOMDocument;
                        $dom->loadHTML($pdf_text);
                        $xpath = new DOMXPath($dom);
                        $nodes = $xpath->query('//img');
                        /** @var DOMElement $node */
                        foreach ($nodes as $node) {
                            $node->removeAttribute('width');
                            $node->removeAttribute('height');
                            // $node->setAttribute('width', '200');
                            $style = $node->getAttribute('style');
                            $style .= ';width: 100%;';
                            $style = $node->setAttribute('style', $style);
                        }
                        $pdf_text = $dom->saveHTML();
                    /*}  finally {
                        // just wrapper
                    }*/
                    $blockForPdf .= "\r\n".'<div>'.$pdf_text.'</div>';
            }
            $blockForPdf .= '</div>';
            $data_for_pdf[$i][] = $blockForPdf;
            $data_for_pdf_blocks[$i][] = $block;
        }
    }
    $general_content .= '</td>';
}

$general_content .= '</tr></table>';
$general_content .= '</div>';

$general_content .= "<br />";
$general_content .= "<div class=''>\n";
$pdflink = $PAGE->url;
$pdflink->params(array(
        'courseid' => optional_param('courseid', 1, PARAM_TEXT),
        'access' => optional_param('access', 0, PARAM_TEXT),
        'ispdf' => 1
));
$general_content .= '<button class="btn btn-default" onclick="location.href=\''.$pdflink.'\'" type="button">'.block_exaport_get_string('download_pdf').' </button>';

$general_content .= "</div>\n";

$general_content .= "<div class='block_eportfolio_center'>\n";

$general_content .= "</div>\n";
if (!$is_pdf) {
    $general_content .= block_exaport_wrapperdivend();
    $general_content .= $OUTPUT->footer();
}

if ($is_pdf) {
    // old pdf view

    require_once (__DIR__.'/lib/classes/dompdf/autoload.inc.php');
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'dejavu sans');
    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A4', 'landscape');
    $general_content = pdf_view($view, $colslayout, $data_for_pdf);
    // echo $general_content;exit;
    $dompdf->loadHtml($general_content);
    $dompdf->render();
    $dompdf->stream('view.pdf'); //To popup pdf as download
    exit;
    /**/

    // new pdf view

    // generate PDF directly. not as HTML. not done fully yet
    /* require_once __DIR__.'/lib/reportlib.php';
    // $pdf = new ExaportViewPdf();
    // $pdf->generatePDFview($view->layout, $data_for_pdf);
    $pdf = new ExaportVievPdf($view);
    $pdf->genarateView($view->layout, $data_for_pdf_blocks, $access);*/
    /**/
}

echo $general_content;


function pdf_view($view, $colslayout, $data_for_pdf) {
    $pdf_content = '<html>';
    $pdf_content .= '<body>';
    $pdf_content .= '<style>
        body {
            /* only dejavu sans supports greek characters, but chinese is not working */
            font-family: "dejavu sans";
            font-size: 14px;
        }
        .view-table td {
            padding: 5px;
        }
        h4 {
            margin: 15px 0 0;
        }
        div.view-block {
            position: relative;
            height: auto;
            clear: both;
            /*background-color: #fefefe;*/
            border-top: 1px solid #eeeeee;
        }
        </style>';
    $pdf_content .= '<table border="0" width="100%" class="view-table" style="table-layout:fixed;">';
    $pdf_content .= '<tr>';
    $max_rows = 0;
    foreach ($data_for_pdf as $col => $blocks) {
        $max_rows = max(count($blocks), $max_rows);
    }
    for ($coli = 1; $coli <= $colslayout[$view->layout]; $coli++) {
        $pdf_content .= '<td width="'.(round(100 / $colslayout[$view->layout]) - 1).'%" valign="top">';
        if (array_key_exists($coli, $data_for_pdf)) {
            $pdf_content .= '<table width="100%" style="word-wrap: break-word !important;">';
            foreach ($data_for_pdf[$coli] as $block) {
                $pdf_content .= '<tr><td>';
                $pdf_content .= $block;
                $pdf_content .= '</td></tr>';
            }
            $pdf_content .= '</table>';
        }

        $pdf_content .= '</td>';
    }
    /* for ($rowI = 0; $rowI < $max_rows; $rowI++) {
        $pdf_content .= '<tr>';

        for ($coli = 1; $coli <= $colslayout[$view->layout]; $coli++) {
            $pdf_content .= '<td width="'.(round(100 / $colslayout[$view->layout]) - 1).'%">';
            if (array_key_exists($coli, $data_for_pdf)) {
                if (array_key_exists($rowI, $data_for_pdf[$coli])) {
                    $pdf_content .= $data_for_pdf[$coli][$rowI];
                }
            }
            $pdf_content .= '</td>';
        }
        $pdf_content .= '</tr>';
    }*/
    $pdf_content .= '</tr>';
    $pdf_content .= '</table>';
    $pdf_content .= '</body>';
    $pdf_content .= '</html>';
    // echo $pdf_content; exit;
    return $pdf_content;
}

?>
