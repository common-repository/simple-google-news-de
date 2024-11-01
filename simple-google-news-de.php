<?php
/**
 * Plugin Name: Simple Google News DE
 * Plugin URI: https://internet-pr-beratung.de/simple-google-news-de
 * Description: Binde mit diesem einfachen Plugin den Google News Stream zu einem bestimmten Thema in die Sidebar, Artikel oder Seite ein. 
 * Version: 1.8
 * Author: <a href="https://internet-pr-beratung.de">Sammy Zimmermanns</a> & <a href="http://itservice-herzog.de">Matthias Herzog</a>
 * License: GPL2
 */
/*  Copyright 2014  Sammy Zimmermanns  (email : info@internet-pr-beratung.de)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License, version 2, as
  published by the Free Software Foundation. 

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */
// Prohibit direct script loading.
defined('ABSPATH') || die('No direct script access allowed!');
define('WP_TEMP_DIR', ini_get('upload_tmp_dir'));


//we need this include to parse the Google News feed with MagPie later on
include_once(ABSPATH . WPINC . '/rss.php');

//Definiere die Standardwerte 
DEFINE("default_limit", "5");
DEFINE("default_region", "de");
DEFINE("default_query", "");
DEFINE("default_topic", "");
DEFINE("default_images", "on");
DEFINE("default_length", "300");
DEFINE("default_sort", "r");

//register and enqueue our (very small) style sheet
function register_google_news_styles()
{
    wp_register_style('google-news-style', plugins_url('/css/style.css', __FILE__), array(), '20120208', 'all');
    wp_enqueue_style('google-news-style');
}

//register the shortcode
add_shortcode('google_news', 'init_google_news');

function init_google_news($atts)
{

    register_google_news_styles();

    //process the incoming values and assign defaults if they are undefined
    $atts = shortcode_atts(array(
        "limit" => default_limit,
        "region" => default_region,
        "query" => default_query,
        "topic" => default_topic,
        "images" => default_images,
        "length" => default_length,
        "sort" => default_sort
            ), $atts);

    //now, let's run the function that does the meat of the work
    $output = get_news($atts);

    //send the output back to the post
    return $output;
}

//by default, the news descriptions are very long. This function will help us shorten them
function shortdesc($desc, $length)
{
    if ($length == '')
    {
        $length = '300';
    }
    $desc = substr($desc, 0, $length);
    $desc = substr($desc, 0, strrpos($desc, " "));
    return $desc;
}

//this function builds and returns the feed URL
function build_feed_url($atts)
{
    $url = 'http://news.google.com/news?q=' . urlencode($atts['query']) . '&topic=' . $atts['topic'] . '&ned=' . $atts['region'] . '&scoring=' . $atts['sort'];
    return $url;
}

//this function calculates relative time
function time_ago($timestamp)
{
    if (!is_numeric($timestamp))
    {
        $timestamp = strtotime($timestamp);
        if (!is_numeric($timestamp))
        {
            return "";
        }
    }

    $difference = time() - $timestamp;
    $periods = array("Sekunde", "Minute", "Stunde", "Tag", "Woche", "Monat", "Jahre", "Dekade");
    $lengths = array("60", "60", "24", "7", "4.35", "12", "10");

    if ($difference > 0)
    { // this was in the past
        $ending = "alt";
    } else
    { // this was in the future
        $difference = -$difference;
        $ending = "to go";
    }
    for ($j = 0; $difference >= $lengths[$j] and $j < 7; $j++)
        $difference /= $lengths[$j];
    $difference = round($difference);
    if ($difference != 1)
    {
        $periods[$j].= "";
    }
    $text = "$difference $periods[$j] $ending";
    return $text;
}

//this function handles all the real work
function get_news($atts)
{
    //if there are single quotes in the query, let's remove them. They'll break things, and they aren't necessary for performing a search
    $atts['query'] = str_replace("'", "", $atts['query']);

    //we also need to replace any spaces with proper word separators
    $atts['query'] = str_replace(" ", "+", $atts['query']);

    //call the build_feed_url function to construct the feed URL for us
    $newsUrl = build_feed_url($atts);



    //call the build_feed function to parse the feed and return the results to us
    $output = build_feed($atts, $newsUrl, $iswidget);

    return $output;
}

//this is the function that actually builds the output
function build_feed($atts, $newsUrl, $iswidget)
{
    //we're using WordPress' built in MagPie support for parsing the Google News feed
    $OrgUrl = $newsUrl;
    $newsUrl.= '&output=rss';
    $Path = __DIR__ . "/temp/";
    $FileName = $Path . $atts['query'];


    if (!is_dir($Path))
    {
        if (@mkdir($Path, "0755", true))
        {
            @chmod($Path, 0755);
        } else
        {
            echo "<strong>Das verzeichniss " . $Path . " ist nicht beschreibbar. Bitte setzten sie die Schreibrechte des verzeichnisses auf 755</strong>";
        }
    }

    if (is_file($FileName))
    {

        $FileTime = filemtime($FileName);
        if ($FileTime < ( time() + 43200 )) // das ist die Cachezeit von 12 stunden in sekunden runter gerechnet
        {
            return file_get_contents($FileName);
        }
    }
    //exit();

    $feed = fetch_rss($newsUrl);
    //$feed = file_get_contents( $newsUrl );
    if (!$feed)
    {
        return "Keine News gefunden";
    }

    $items = array_slice($feed->items, 0, $atts['limit']);

    //if there are results, loop through them
    if (!empty($items))
    {

        $output .= '<div id="googlenewscontainer">';

        foreach ($items as $item)
        {
            //Google News adds the source to the title. I don't like the way that looks, so I'm getting rid of it. We'll add the source ourselves later on
            $title = explode(' - ', $item['title']);

            //calculate the relative time
            $relDate = time_ago($item['pubdate']);

            //by default, Google lumps in the image with the description. We're pull the image out.
            preg_match('~<img[^>]*src\s?=\s?[\'"]([^\'"]*)~i', $item['description'], $imageurl);

            $output .= '<div class="newsresult">';

            //$output .= $pubDate;
            //by default, the news descriptions are full of ugly markup including tables, font definitions, line breaks, and other things.
            //to make it look nice on any site, we're going to strip all the formatting from the news descriptions
            preg_match('@src="([^"]+)"@', $item['description'], $match);

            $description = explode('<font size="-1">', $item['description']);
            $description = strip_tags($description[2]);
            $description = shortdesc($description, $atts['length']);

            //if there is a news image, let's show it. If there isn't one, we'll show a blank space there instead
            //this is done for consistent formatting
            if (strtolower($atts['images']) == 'on')
            {
                if ($imageurl[0] != '')
                {
                    $output .= '<a href="' . $item['link'] . '" class="google_news_title" rel="nofollow" target="_blank"><div class="newsimage"><img src="' . $imageurl[1] . '" alt="' . $title[0] . '" title="' . $title[0] . '"/></a></div>';
                }
            }

            $output .= '<a title="' . $title[0] . '" href="' . $item['link'] . '" class="google_news_title" rel="nofollow" target="_blank">' . $title[0] . '</a>';
            $output .= '<p><span class="smallattribution">' . $title[1] . ' - ' . $relDate . '</span><br />' . $description . '...</p>';
            //this attribution is required by the Google News terms of use
            $output .= '</div>';
        }

        //we need to add a link to Google's search results to comply with their terms of use
        if ($atts['query'] != '')
        {
            $output .= '<p class="googleattribution">News via Google. <a title="News Plug-In powered by internet-pr-beratung.de" rel="nofollow" href="' . $OrgUrl . '">Noch mehr News zum Thema \'' . str_replace("+", " ", $atts['query']) . '\'</a></p>';
        } else
        {
            $output .= '<p class="googleattribution">News via Google. <a title="News Plug-In powered by internet-pr-beratung.de" rel="nofollow" href="' . $OrgUrl . '">Noch mehr News entdecken</a></p>';
        }

        $output .= '<div class="clear"></div>';
        $output .= '</div>';


        if (is_dir($Path))
        {
            @unlink($FileName); // datei entfernen
            @file_put_contents($FileName, $output); // datei neu schreiben
        }



        return $output;
    }
    return "Keine News gefunden!";
}

// Add Quicktags
function custom_quicktags()
{

    if (wp_script_is('quicktags'))
    {
        ?>
        <script type="text/javascript">
            QTags.addButton('5', 'Google News', '[google_news region="de" query="" limit=""]', '', '', 'Google News', 1);
        </script>
        <?php
    }
}

add_action('admin_print_footer_scripts', 'custom_quicktags');

//The widget code starts here

class google_news_widget extends WP_Widget
{

    // constructor

    function __construct()
    {
        parent::__construct(false, $name = __('Google News Widget', 'wp_widget_plugin'));
    }

    // widget form creation
    function form($instance)
    {
        // Check values

        $defaults = array('query' => '', 'limit' => '5', 'region' => 'de', 'images' => 'On', 'sort' => 'Relevance', 'length' => '300', 'title' => '');
        $instance = wp_parse_args((array) $instance, $defaults);
        ?>
        <!-- Start widget title -->
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Titel', 'wp_widget_plugin'); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $instance['title']; ?>" />
        </p>


        <!-- Start query options -->
        <p>
            <label for="<?php echo $this->get_field_id('query'); ?>"><?php _e('Suchbegriff (optional)', 'wp_widget_plugin'); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('query'); ?>" name="<?php echo $this->get_field_name('query'); ?>" type="text" value="<?php echo $instance['query']; ?>" />
        </p>

        <!-- Start result limit -->
        <p>
            <label for="<?php echo $this->get_field_id('limit'); ?>"><?php _e('Ergebnis Limit', 'wp_widget_plugin'); ?></label>
            <select name="<?php echo $this->get_field_name('limit'); ?>" id="<?php echo $this->get_field_id('limit'); ?>" class="widefat">

        <?php
        $options = array('1', '2', '3', '4', '5', '6', '7', '8', '9', '10');
        foreach ($options as $option)
        {
            ?>
                    <option <?php selected($instance['limit'], $option); ?> value="<?php echo $option; ?>"><?php echo $option; ?></option>
                    <?php
                }
                ?>
            </select>
        </p>
        <!-- Start region -->
        <p>
            <label for="<?php echo $this->get_field_id('region'); ?>"><?php _e('Region', 'wp_widget_plugin'); ?></label>
            <select name="<?php echo $this->get_field_name('region'); ?>" id="<?php echo $this->get_field_id('region'); ?>" class="widefat">

        <?php
        $options = array('de', 'de_at', 'de_ch', 'us', 'uk');
        foreach ($options as $option)
        {
            ?>
                    <option <?php selected($instance['region'], $option); ?> value="<?php echo $option; ?>"><?php echo $option; ?></option>
                    <?php
                }
                ?>
            </select>
        </p>
        <!-- Start length options -->
        <p>
            <label for="<?php echo $this->get_field_id('length'); ?>"><?php _e('Länge', 'wp_widget_plugin'); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('length'); ?>" name="<?php echo $this->get_field_name('length'); ?>" type="text" value="<?php echo $instance['length']; ?>" />
        </p>

        <!-- Start image options -->
        <p>
            <label for="<?php echo $this->get_field_id('images'); ?>"><?php _e('Bilder', 'wp_widget_plugin'); ?></label>
            <select name="<?php echo $this->get_field_name('images'); ?>" id="<?php echo $this->get_field_id('images'); ?>" class="widefat">

        <?php
        $options = array('On', 'Off');
        foreach ($options as $option)
        {
            ?>
                    <option <?php selected($instance['images'], $option); ?> value="<?php echo $option; ?>"><?php echo $option; ?></option>
            <?php
        }
        ?>
            </select>
        </p>

        <!-- Start Sort Options -->

        <p>
            <label for="<?php echo $this->get_field_id('sort'); ?>"><?php _e('Ergebnisse sortiert nach', 'wp_widget_plugin'); ?>:</label>
            <select id="<?php echo $this->get_field_id('sort'); ?>" name="<?php echo $this->get_field_name('sort'); ?>">
                <option value="r" <?php selected(r, $instance['sort']); ?>><?php _e('Relevanz'); ?></option>
                <option value="n" <?php selected(n, $instance['sort']); ?>><?php _e('Datum'); ?></option>
            </select>
        </p>

        <?php
    }

    // widget update
    function update($new_instance, $old_instance)
    {
        $instance = $old_instance;
        $instance['query'] = strip_tags($new_instance['query']);
        $instance['limit'] = strip_tags($new_instance['limit']);
        $instance['region'] = strip_tags($new_instance['region']);
        $instance['images'] = strip_tags($new_instance['images']);
        $instance['sort'] = strip_tags($new_instance['sort']);
        $instance['length'] = strip_tags($new_instance['length']);
        $instance['title'] = strip_tags($new_instance['title']);
        return $instance;
    }

    // widget display
    function widget($args, $instance)
    {
        extract($args);
        // these are the widget options

        register_google_news_styles();

        $newsUrl = build_feed_url($instance);

        $iswidget = 'yes';
        $myfeed = build_feed($instance, $newsUrl, $iswidget);
        echo "<h3>" . $instance['title'] . "</h3>" . $myfeed;
        //echo $myfeed;
        //echo $after_widget;
    }

}

// register widget
add_action('widgets_init', create_function('', 'return register_widget("google_news_widget");'))
?>
<?php
/** Admin Panel */
add_action('admin_menu', 'simple_google_news_de_menu');

function simple_google_news_de_menu()
{
    add_options_page('Simple Google News DE Options', 'Simple Google News DE', 'manage_options', 'simple-google-news-de', 'simple_google_news_de_options');
}

/**
 * entfernt die cache datein
 */
function deteleSGNCache()
{
    $Path = __DIR__ . '/temp/';
    rm_folder_recursively($Path);

    echo "<h2 style='color:red;font-weight:bold'> Cache wurde entfernt</h2>";
}

function rm_folder_recursively($dir, $i = 1)
{
    $files = @scandir($dir);
    foreach ((array) $files as $file)
    {
        if ($i > 50)
        {
            return true;
        } else
        {
            $i++;
        }
        if ('.' === $file || '..' === $file)
            continue;
        if (is_dir("$dir/$file"))
            rm_folder_recursively("$dir/$file", $i);
        else
            @unlink("$dir/$file");
    }

    @rmdir($dir);
    return true;
}

function simple_google_news_de_options()
{

    if (!current_user_can('manage_options'))
    {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $action = (isset($_REQUEST['action']) ? $_REQUEST['action'] : null);
    if ($action == 'reset')
    {
        // cache entfernen
        deteleSGNCache();
    }

    echo '<div class="wrap">';

    $Error = 0;
    $Path = __DIR__ . '/temp/';
    if (!is_dir($Path))
    {
        if (@mkdir($Path, "0755", true))
        {
            @chmod($Path, 0755);
        } else
        {
            $Error++;
        }
    }

    $FileName = $Path . time();
    if (is_dir($Path))
    {
        // datei entfernen
        if (@file_put_contents($FileName, "test"))
        {
            @unlink($FileName);
        } else
        {
            $Error++;
        }
    } else
    {
        $Error++;
    }


    if ($Error)
    {
        echo '<h2 style="color:red">Cache Kann nicht angelegt werden! bitte setzen sie das Verzeichniss ' . __DIR__ . '/temp/ auf 775 </h2>';
    }

    echo '<h2>Shortcode Beispiele:</h2>';
    
    echo '<h2><a href="' . admin_url('options-general.php?page=simple-google-news-de&action=reset') . '"><button>cache leeren</button></a></h2>';
    echo '<h3>Ein einfacher Shortcode</h3>';
    echo '<code>[google_news]</code>';
    echo '<h3>Shortcode für 2 Nachrichten zu einem bestimmten Thema</h3>';
    echo '<code>[google_news limit="2" topic="t"]</code><br/><br/>';
    echo 'Diese Themen Werte kannst Du nutzen:
<br/><br/>
b	= Wirtschaft<br/>
t	= Technik<br/>
e	= Unterhaltung<br/>
s	= Sport<br/>
snc	= Wissenschaft<br/>
m	= Gesundheit<br/>
ir	= Schlagzeilen<br/>';
    echo '<h3>Nachrichten zu einem bestimmten Suchbegriff</h3>';
    echo '<code>[google_news query="Dein Suchbegriff"]</code>';
    echo '<h3>Nachrichten aus einer bestimmten Region</h3>';
    echo '<code>[google_news region="de" query="Dein Suchbegriff"]</code><br/><br/>';
    echo 'Diese Themen Werte kannst Du nutzen:
<br/><br/>
de	= Deutschland<br/>
de_at	= Österreich<br/>
de_ch	= Schweiz<br/>
us	= USA<br/>
uk	= Großbritannien<br/>';
    echo '<h2>Impressum von Simple Google News DE</h2>';

    echo '<table class="form-table">
			<tbody>
				<tr>
					<th scope="row">Autoren</th>
					<td>
						<p>
							<a href="https://internet-pr-beratung.de">
								<img class="sgnde-about-logo" src="/wp-content/plugins/simple-google-news-de/images/internet-pr-beratung-logo.png" alt="Zimmermanns Internet & PR-Beratung">
							</a>
						</p>
						<p>
							Sammy Zimmermanns<br>Waldheimer Str. 16a<br>01159 Dresden						</p>
						<p>
							E-Mail: <a href="mailto:info@internet-pr-beratung.de">info@internet-pr-beratung.de</a><br>Website: <a title="internet-pr-beratung.de" href="https://internet-pr-beratung.de">internet-pr-beratung.de</a>						</p>
                       </td>
					   <td>					   
					   <p>
							<a href="http://itservice-herzog.de/">
								<img class="sgnde-about-logo" src="/wp-content/plugins/simple-google-news-de/images/it-service-herzog-logo.png" alt="IT-Service Herzog">
							</a>
						</p>
						<p>
							Matthias Herzog<br>Großenhainer Str. 17<br>01561 Schönfeld						</p>
						<p>
							E-Mail: <a href="mailto:info@itservice-herzog.de">info@itservice-herzog.de</a><br>Website: <a title="IT-Service Herzog" href="http://itservice-herzog.de/">itservice-herzog.de</a>						</p>
					</td>
				</tr>
			

			</tbody>
		</table>';
    echo '</div>';
}
?>