<div class="banner banner_2"></div>
<div class="main">
	<div class="position">当前位置：<?php if(function_exists('bcn_display'))
    {
      bcn_display();
    }?></div>
	<div class="mr">
		<div class="page_title"><strong><?php single_cat_title(); ?></strong></div>
		<div class="page_main">
			<div class="list_product">
      <?php $my_query=new WP_Query(array(
    'cat'				=>get_query_var('cat'),
    'paged'				=>get_query_var('paged'),
    ))?>
				<ul>
        <?php if($my_query->have_posts()):while($my_query->have_posts()):$my_query->the_post();?>
          <li>
            <figure>
              <?php if ( has_post_thumbnail() ) { ?>
                <img src="<?php the_post_thumbnail_url('thumbnail'); ?>" alt="<?php the_title(); ?>">
              <?php } else { ?>
                <img src="<?php echo get_template_directory_uri(); ?>/images/nopic.jpg" alt="<?php the_title(); ?>" />
              <?php } ?>
              <figcaption>
                <strong><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></strong>
                <span><?php echo mb_strimwidth(strip_tags($post->post_content),0,150,'...'); ?></span>
              </figcaption>
            </figure>
          </li>
          <?php endwhile; ?>
          <?php else: ?>
          <p>暂无内容</p>
          <?php endif; ?>
				</ul>
			</div>
			<div class="pages">
      <?php wp_pagenavi(array( 'query' => $my_query )); ?>
			</div>
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