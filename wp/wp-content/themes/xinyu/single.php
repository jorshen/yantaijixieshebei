<?php get_header(); ?>
<div class="banner banner_2"></div>
<div class="main">
	<div class="position">当前位置：<?php if(function_exists('bcn_display'))
    {
      bcn_display();
    }?></div>
	<div class="mr">
		<div class="page_title"><strong><?php echo get_the_category()[0]->cat_name; ?></strong></div>
		<div class="page_main">
    <?php if( have_posts() ) : while( have_posts() ) : the_post(); ?>
      <div class="article_article">
				<h1><?php the_title(); ?></h1>
				<div class="article_info">浏览：<?php get_post_views($post -> ID); ?>    发布于：<?php the_time('Y-m-d'); ?></div>
				<div class="article_body">
        <?php the_content(); ?>
				</div>
				<div class="pre_next">
					<ol>
						<li class="pre"><?php previous_post_link('上一篇：%link'); ?></li>
						<li class="next"><?php next_post_link('下一篇：%link'); ?></li>
					</ol>
				</div>
			</div>
      <?php endwhile; ?>
      <?php endif; ?>
		</div>
	</div>
	<div class="ml">
		<div class="category">
	<div class="catname">
		<strong>产品展示</strong>
	</div>
	<div class="subcat">
		<ul>
    <?php $args=array('orderby' => 'ID','order' => 'ASC','child_of' => 3,'hide_empty'=>0);
    $categories=get_categories($args);
    foreach($categories as $category) {
        if(($category->term_id)==$cat){
            echo '<li class="current"><a href="'.get_category_link($category->term_id).'" target="_self">'.$category->name.'</a></li>';
        }else{
            echo '<li><a href="'.get_category_link($category->term_id).'" target="_self">'.$category->name.'</a></li>';
        }
    }
    ?>
		</ul>
	</div>
</div>
	</div>
</div>
<?php get_footer(); ?>